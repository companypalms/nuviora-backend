<?php

namespace App\Services\Reconciliation;

use App\Models\BankStatementRow;
use App\Models\CompanyAccount;
use App\Models\ReconciliationDay;
use App\Models\ReceiptCheck;
use App\Models\ReconciliationItem;
use App\Models\StatementSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Busca cada pago digital de un día en los extractos subidos para ese día (documento de Fran del 2026-10-06):
 * - §18: el monto real del comprobante, al céntimo, más la referencia y la cuenta. Aquí no se aplica el 5 %.
 * - §19: la referencia se compara solo con sus dígitos y sin ceros a la izquierda. Además, cada banco la escribe con su
 *   formato (Banesco muestra 11 dígitos; en Provincial el comprobante trae 9 con ceros adelante; otros, 12): con el
 *   mismo monto, que coincidan los últimos 6 dígitos es la misma referencia ("diferencias de formato"). Lo decidió el
 *   usuario el 2026-10-07 con el extracto real del 5 y el 6 de octubre (infra/conciliacion/informe-tarea0.md). La IA
 *   nunca decide que dos referencias son iguales: son reglas fijas.
 * - §20: Conciliado, Pago no encontrado o Requiere revisión (hay una posible coincidencia sin seguridad suficiente).
 *   Lo que resolvió el administrador no se toca.
 * - §21: cada pago se concilia por separado y un movimiento del extracto sirve para un solo pago.
 * - §23: solo se busca lo que el negocio registró; los demás movimientos del extracto no se miran.
 * - `.115` (el dueño, 2026-11-07): un pago en bolívares que no está en el extracto de su banco se busca en el de los
 *   otros bancos de la empresa (el cliente, la vendedora o el motorizado se equivocó de cuenta). Primero cada banco se
 *   queda con sus pagos; con lo que sobra, la misma referencia y el mismo monto al céntimo lo concilian y el pago pasa a
 *   la cuenta donde llegó (también en la caja). Si el comprobante no dice la cuenta, se concilia en el banco donde está.
 * Son reglas fijas, sin IA: con los mismos datos, siempre el mismo resultado.
 */
class StatementMatcher
{
    /** Cuántos días antes de la fecha del comprobante y después del día se mira el extracto. */
    private const DAYS_BEFORE = 1;
    private const DAYS_AFTER = 2;

    /** Cuántos dígitos del final de la referencia tienen que coincidir cuando el banco la escribe con otro formato. */
    private const TAIL = 6;

    private const MAX_CANDIDATES = 5;

    /** Pago móvil y transferencia: bolívares que llegan a un banco de la empresa (`.115`). */
    public const BS = ['pago_movil', 'transferencia'];

    public function matchDay(ReconciliationDay $day): void
    {
        $statements = $day->statements()->current()->get();
        if ($statements->isEmpty()) {
            return;
        }
        $sourceOf = $statements->mapWithKeys(fn ($s) => [$s->id => (int) $s->statement_source_id]);
        $uploaded = $sourceOf->unique()->values()->all();
        $sourceName = StatementSource::pluck('name', 'id');
        $rows = BankStatementRow::whereIn('bank_statement_id', $statements->pluck('id'))->orderBy('id')->get()
            ->each(fn ($r) => $r->source_id = $sourceOf[$r->bank_statement_id]);
        $accounts = CompanyAccount::where('is_active', true)->whereNotNull('statement_source_id')->get();
        $methodSources = $this->methodSources($accounts);
        $bs = $this->bsSources($accounts);

        $items = $day->items()->orderBy('id')->get();
        // La cuenta que dice el comprobante, para avisar cuando el pago está en otro banco (también al buscar otra vez)
        $declared = ReceiptCheck::whereIn('id', $items->pluck('receipt_check_id')->filter())->pluck('company_account_id', 'id');
        $declaredAccounts = CompanyAccount::whereIn('id', $declared->filter())->get()->keyBy('id');
        // Lo que decidió el administrador se queda como está, con su movimiento (§20). Movimiento usado => orden
        $used = $items->whereIn('status', ReconciliationItem::MANUAL)->whereNotNull('bank_statement_row_id')
            ->mapWithKeys(fn ($i) => [$i->bank_statement_row_id => $i->order_name ?? "#{$i->order_id}"])->all();
        // Dónde se busca cada pago: en el extracto de su banco y, en bolívares, en el de los otros bancos (`.115`)
        $own = $other = [];
        foreach ($items->whereNotIn('status', ReconciliationItem::MANUAL) as $item) {
            $mine = $this->sources($item, $methodSources);
            $own[$item->id] = array_values(array_intersect($mine, $uploaded));
            $other[$item->id] = array_values(array_intersect($this->otherSources($item, $mine, $bs), $uploaded));
        }
        // Solo los pagos con algún extracto subido donde buscarlos
        $todo = $items->filter(fn ($i) => ($own[$i->id] ?? []) !== [] || ($other[$i->id] ?? []) !== []);

        $sameRef = fn ($item) => fn ($r) => $r->reference_norm === $item->reference_norm;
        $sameTail = fn ($item) => fn ($r) => $this->tail($r->reference) === $this->tail($item->reference);
        $passes = [
            // 1. Misma referencia y mismo monto, en el banco del pago
            ['own', 'reference_norm', $sameRef, 'Misma referencia y mismo monto', 'Hay %d movimientos con la misma referencia y el mismo monto.'],
            // 2. Mismo monto y la referencia termina igual (los últimos 6 dígitos): el formato de cada banco (§19)
            ['own', 'tail', $sameTail, 'Mismo monto, la referencia termina igual', 'Hay %d movimientos con el mismo monto y la referencia que termina igual.'],
            // 3 y 4. Lo mismo en los otros bancos en bolívares, con lo que sus propios pagos no usaron (`.115`)
            ['other', 'reference_norm', $sameRef, 'Misma referencia y mismo monto', 'Hay %d movimientos con la misma referencia y el mismo monto.'],
            ['other', 'tail', $sameTail, 'Mismo monto, la referencia termina igual', 'Hay %d movimientos con el mismo monto y la referencia que termina igual.'],
        ];
        $results = [];
        foreach ($passes as [$where, $needs, $same, $reason, $many]) {
            foreach ($todo as $item) {
                $sources = $where === 'own' ? $own[$item->id] : $other[$item->id];
                $usable = $needs === 'tail' ? $this->tail($item->reference) !== null : $item->reference_norm !== null;
                if (isset($results[$item->id]) || $sources === [] || $item->amount === null || !$usable) {
                    continue;
                }
                $test = $same($item);
                $found = $rows->filter(fn ($r) => !isset($used[$r->id]) && in_array($r->source_id, $sources, true) && $test($r)
                    && $this->sameAmount($r->amount, $item->amount) && $this->inWindow($r, $item, $day));
                if ($found->count() === 1) {
                    $row = $found->first();
                    $used[$row->id] = $item->order_name;
                    $moved = $row->source_id !== (int) $item->statement_source_id ? $this->accountIn($accounts, $row->source_id, $item->method) : null;
                    $results[$item->id] = [ReconciliationItem::MATCHED, $row->id, $this->matchedNote($item, $row, $reason, $declared, $declaredAccounts, $sourceName), null, $moved];
                } elseif ($found->isNotEmpty()) {
                    $results[$item->id] = [ReconciliationItem::REVIEW, null, sprintf($many, $found->count()),
                        $found->take(self::MAX_CANDIDATES)->map(fn ($r) => $this->candidate($r, $reason))->values()->all(), null];
                }
            }
        }

        // 5. Lo demás, si ya está el extracto de su banco: posibles coincidencias para revisar, o no encontrado. Si solo
        // está el de otro banco, se espera al suyo
        foreach ($todo as $item) {
            if (isset($results[$item->id]) || $own[$item->id] === []) {
                continue;
            }
            if ($item->amount === null && $item->reference_norm === null) {
                $results[$item->id] = [ReconciliationItem::NOT_FOUND, null, $item->match_note ?: 'El comprobante no tiene datos para buscarlo en el extracto.', null, null];
                continue;
            }
            $possible = collect();
            foreach ($rows as $r) {
                $inOwn = in_array($r->source_id, $own[$item->id], true);
                if ((!$inOwn && !in_array($r->source_id, $other[$item->id], true)) || !$this->inWindow($r, $item, $day)) {
                    continue;
                }
                $isRef = $item->reference_norm !== null && $r->reference_norm === $item->reference_norm;
                $isTail = $this->tail($item->reference) !== null && $this->tail($r->reference) === $this->tail($item->reference);
                $isAmount = $item->amount !== null && $this->sameAmount($r->amount, $item->amount);
                $in = $inOwn ? '' : ' en el extracto de ' . ($sourceName[$r->source_id] ?? '?');
                if ($isRef || $isTail) {
                    $possible->push([$r, isset($used[$r->id]) ? ($isRef ? 'Misma referencia' : 'La referencia termina igual') . $in . '; ese movimiento ya es de la orden ' . $used[$r->id]
                        : ($isRef ? 'Misma referencia, otro monto' : 'La referencia termina igual, otro monto') . $in]);
                } elseif ($inOwn && !isset($used[$r->id]) && $isAmount) {
                    // En los otros bancos el mismo monto solo no dice nada: muchos pagos son del mismo precio
                    $possible->push([$r, $r->reference_norm === null ? 'Mismo monto, sin referencia' : 'Mismo monto, otra referencia']);
                }
            }
            if ($possible->isEmpty()) {
                $results[$item->id] = [ReconciliationItem::NOT_FOUND, null, $this->notFoundNote($item, $own[$item->id], $other[$item->id], $methodSources, $bs, $uploaded, $sourceName), null, null];
                continue;
            }
            $results[$item->id] = [ReconciliationItem::REVIEW, null, $possible->first()[1] . '.', $possible->take(self::MAX_CANDIDATES)->map(fn ($p) => $this->candidate($p[0], $p[1]))->values()->all(), null];
        }

        foreach ($todo as $item) {
            if (!isset($results[$item->id])) {
                continue;
            }
            [$status, $rowId, $note, $candidates, $moved] = $results[$item->id];
            $item->forceFill(['status' => $status, 'bank_statement_row_id' => $rowId, 'match_note' => $note, 'candidates' => $candidates]);
            if ($moved) {
                // Llegó a otra cuenta de la empresa: el pago pasa a esa (también su entrada en la caja)
                $item->forceFill(['company_account_id' => $moved->id, 'statement_source_id' => $moved->statement_source_id]);
            }
            if ($item->isDirty()) {
                $item->save();
            }
        }
    }

    /** Los extractos de las cuentas activas en bolívares (`.115`). */
    public function bsSources(?Collection $accounts = null): array
    {
        $accounts ??= CompanyAccount::where('is_active', true)->whereNotNull('statement_source_id')->get();

        return $accounts->whereIn('method', self::BS)->pluck('statement_source_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Todos los extractos donde puede estar un pago: el de su banco y, en bolívares, los de los otros (`.115`). */
    public function searchSources(ReconciliationItem $item): array
    {
        $accounts = CompanyAccount::where('is_active', true)->whereNotNull('statement_source_id')->get();
        $mine = $this->sources($item, $this->methodSources($accounts));

        return array_values(array_unique(array_merge($mine, $this->otherSources($item, $mine, $this->bsSources($accounts)))));
    }

    private function methodSources(Collection $accounts): Collection
    {
        return $accounts->groupBy('method')->map(fn ($g) => $g->pluck('statement_source_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
    }

    /** Los extractos donde se busca el pago: el de su cuenta o, si no se sabe la cuenta, los de su método. */
    private function sources(ReconciliationItem $item, Collection $methodSources): array
    {
        return $item->statement_source_id ? [(int) $item->statement_source_id] : ($methodSources[$item->method] ?? []);
    }

    /** `.115`: los otros bancos en bolívares, donde se busca un pago móvil o una transferencia que no está en el suyo. */
    private function otherSources(ReconciliationItem $item, array $mine, array $bs): array
    {
        return in_array($item->method, self::BS, true) ? array_values(array_diff($bs, $mine)) : [];
    }

    /** `.115`: la cuenta activa de ese extracto a la que llegó un pago (al vincularlo a mano con un movimiento de otro banco). */
    public function accountFor(int $sourceId, string $method): ?CompanyAccount
    {
        return $this->accountIn(CompanyAccount::where('is_active', true)->whereNotNull('statement_source_id')->get(), $sourceId, $method);
    }

    /** La cuenta del pago en ese extracto: la del mismo método o, en bolívares, cualquiera de ese banco. */
    private function accountIn(Collection $accounts, int $sourceId, string $method): ?CompanyAccount
    {
        $there = $accounts->where('statement_source_id', $sourceId);

        return $there->firstWhere('method', $method) ?? (in_array($method, self::BS, true) ? $there->whereIn('method', self::BS)->first() : null);
    }

    /** "Misma referencia y mismo monto en la fila 12 del extracto.", y dónde llegó si no es el banco del comprobante. */
    private function matchedNote(ReconciliationItem $item, BankStatementRow $row, string $reason, Collection $declared, Collection $declaredAccounts, Collection $sourceName): string
    {
        $where = $sourceName[$row->source_id] ?? '?';
        $said = $item->receipt_check_id ? $declaredAccounts->get($declared[$item->receipt_check_id] ?? 0) : null;
        if ($item->receipt_check_id && !$said) {
            return "{$reason} en la fila {$row->line} del extracto de {$where}. El comprobante no deja ver a qué cuenta fue: llegó a {$where}.";
        }
        if ($said && (int) $said->statement_source_id !== $row->source_id) {
            return "{$reason} en la fila {$row->line} del extracto de {$where}, no en el de " . ($sourceName[$said->statement_source_id] ?? "«{$said->name}»")
                . ': el cliente pagó a la otra cuenta.';
        }

        return $reason === 'Misma referencia y mismo monto'
            ? "Misma referencia y mismo monto en la fila {$row->line} del extracto."
            : "Mismo monto y la referencia termina igual (el banco la escribe con otro formato) en la fila {$row->line} del extracto.";
    }

    /** "No está en el extracto de Banesco ni en el de Bancamiga.", y qué extracto falta para buscarlo ahí (`.115`). */
    private function notFoundNote(ReconciliationItem $item, array $own, array $other, Collection $methodSources, array $bs, array $uploaded, Collection $sourceName): string
    {
        $names = fn (array $ids, string $glue) => collect($ids)->map(fn ($id) => $sourceName[$id] ?? null)->filter()->unique()->implode($glue);
        $note = 'No está en el extracto de ' . $names(array_merge($own, $other), ' ni en el de ') . '.';
        $mine = $this->sources($item, $methodSources);
        $missing = array_values(array_diff(array_merge($mine, $this->otherSources($item, $mine, $bs)), $uploaded));

        return $missing !== [] ? $note . ' Falta el extracto de ' . $names($missing, ' y el de ') . ' para buscarlo ahí.' : $note;
    }

    /** Del día anterior a la fecha del comprobante hasta dos días después del día de la conciliación. */
    private function inWindow(BankStatementRow $row, ReconciliationItem $item, ReconciliationDay $day): bool
    {
        if (!$row->date) {
            return true;
        }
        $from = Carbon::parse($item->paid_on ?? $day->date)->subDays(self::DAYS_BEFORE)->startOfDay();
        $to = Carbon::parse($day->date)->addDays(self::DAYS_AFTER)->endOfDay();

        return Carbon::parse($row->date)->between($from, $to);
    }

    /** §18: el monto tiene que coincidir con el del comprobante, al céntimo. */
    private function sameAmount(float $a, float $b): bool
    {
        return (int) round($a * 100) === (int) round($b * 100);
    }

    /** Los últimos 6 dígitos de la referencia tal como se ve, con sus ceros ("000023531" → "023531"). Null si tiene menos. */
    private function tail(?string $reference): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $reference);

        return strlen($digits) >= self::TAIL ? substr($digits, -self::TAIL) : null;
    }

    private function candidate(BankStatementRow $row, string $reason): array
    {
        return [
            'row_id' => $row->id,
            'line' => $row->line,
            'date' => $row->date?->toDateString(),
            'reference' => $row->reference,
            'amount' => $row->amount,
            'description' => $row->description,
            'reason' => $reason,
        ];
    }
}
