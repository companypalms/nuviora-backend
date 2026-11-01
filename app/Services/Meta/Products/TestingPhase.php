<?php

namespace App\Services\Meta\Products;

use App\Models\AdAngle;
use App\Models\CreativeBatch;
use App\Models\ProductPersona;
use App\Models\ProductProfile;
use App\Services\Meta\Queue\ProposalRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La fase de testeo de cada producto (`.65`), con la base de datos. Las reglas, en TestingRules.
 * - Los ángulos de la ficha son ad_angles con `position` (1 = el más fuerte); los demás códigos del diccionario (los que
 *   el sistema encontró en los nombres de Meta) no están en la ficha.
 * - Un producto "En testeo" tiene una ronda (testing_round); sus batches llevan esa ronda. Cuando todos se testearon, la
 *   ronda se cierra (testing_results) y, si algún ángulo ganó, el producto pasa solo a "Iterar lo que funciona".
 */
class TestingPhase
{
    /** Cuántos días se sigue mostrando en Cola y banca el aviso de un testeo que terminó con ganador. */
    public const NOTICE_DAYS = 14;

    /** @return list<array{id: int, code: string, name: ?string, hypothesis: ?string, persona_id: ?int, persona: ?string}> los ángulos de la ficha, del más fuerte al más débil */
    public static function angles(int $productId): array
    {
        return self::anglesMany([$productId])[$productId];
    }

    /** @return array<int, list<array>> por producto */
    public static function anglesMany(array $productIds): array
    {
        $rows = AdAngle::whereIn('product_id', $productIds ?: [0])->whereNotNull('position')->whereNull('alias_of')->orderBy('position')->get();
        $personas = ProductPersona::whereIn('id', $rows->pluck('persona_id')->filter()->unique()->all() ?: [0])->pluck('name', 'id');
        $out = array_fill_keys(array_map('intval', $productIds), []);
        foreach ($rows as $a) {
            $out[(int) $a->product_id][] = ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'hypothesis' => $a->hypothesis,
                'persona_id' => $a->persona_id, 'persona' => $a->persona_id ? ($personas[$a->persona_id] ?? null) : null];
        }

        return $out;
    }

    /**
     * Guarda los ángulos de la ficha en el orden en que vienen (el primero, el más fuerte). Si el código ya está en el
     * diccionario (porque salió en Meta), se usa ese; los que ya no vienen salen de la ficha, pero no se borran.
     *
     * @param list<array{code: string, name: ?string, hypothesis: ?string, persona: ?string}> $angles ProductProfileRules::normalizeAngle
     */
    public static function saveAngles(int $productId, array $angles, ?int $userId): void
    {
        $personas = ProductPersona::where('product_id', $productId)->whereNull('archived_at')->get(['id', 'name'])
            ->mapWithKeys(fn ($p) => [ProductProfileRules::key($p->name) => $p->id]);
        $rows = AdAngle::where('product_id', $productId)->get()->keyBy('code');
        $codes = [];
        foreach (array_values($angles) as $i => $a) {
            $row = $rows->get($a['code']) ?? new AdAngle(['product_id' => $productId, 'code' => $a['code'], 'proposed_by' => 'owner']);
            if ($row->alias_of) {
                $to = $rows->firstWhere('id', $row->alias_of)?->code;
                throw ValidationException::withMessages(['angles' => "{$a['code']} está unido a {$to} en Ángulos: usa {$to}."]);
            }
            $row->fill(['name' => $a['name'], 'hypothesis' => $a['hypothesis'], 'position' => $i + 1,
                'persona_id' => $a['persona'] !== null ? ($personas[ProductProfileRules::key($a['persona'])] ?? null) : null,
                'status' => 'confirmed', 'confirmed_by' => $userId, 'confirmed_at' => $row->status === 'confirmed' && $row->confirmed_at ? $row->confirmed_at : now()])->save();
            $codes[] = $a['code'];
        }
        AdAngle::where('product_id', $productId)->whereNotNull('position')->when($codes !== [], fn ($q) => $q->whereNotIn('code', $codes))->update(['position' => null]);
    }

    /** "En testeo" empieza una ronda nueva; "Iterar lo que funciona" la deja. Sin cambio si ya estaba en esa fase. */
    public static function setPhase(int $productId, string $phase): void
    {
        $p = ProductProfile::where('product_id', $productId)->first();
        if (!$p || !isset(TestingRules::PHASES[$phase]) || $p->phase === $phase) {
            return;
        }
        $p->forceFill(['phase' => $phase, 'phase_at' => now(), 'testing_round' => $phase === 'testing' ? $p->testing_round + 1 : $p->testing_round])->save();
    }

    /** "Probar los siguientes": otra ronda con los ángulos de la ficha que no salieron en las anteriores. */
    public static function nextRound(int $productId): void
    {
        $p = ProductProfile::where('product_id', $productId)->firstOrFail();
        $st = self::round($productId);
        if ($st['phase'] === 'testing' && $st['round'] > 0 && $st['closed'] === null) {
            throw ValidationException::withMessages(['round' => "La ronda {$st['round']} del testeo todavía no termina: espera los resultados de sus batches."]);
        }
        if ($st['next'] === []) {
            throw ValidationException::withMessages(['round' => 'No quedan ángulos de la ficha sin probar: agrega ángulos nuevos a la ficha.']);
        }
        $p->forceFill(['phase' => 'testing', 'phase_at' => now(), 'testing_round' => $p->testing_round + 1])->save();
    }

    /**
     * La ronda actual de un producto: los batches que lleva (uno por lugar), cómo terminó y lo que queda para otra ronda.
     *
     * @return array{phase: string, round: int, slots: list<array>, outcome: ?array, closed: ?array, next: list<string>, no_angles: bool}
     */
    public static function round(int $productId): array
    {
        $p = ProductProfile::where('product_id', $productId)->first();
        $phase = $p->phase ?? 'iterate';
        $round = (int) ($p->testing_round ?? 0);
        $ordered = array_column(self::angles($productId), null, 'code');
        $empty = ['phase' => $phase, 'round' => $round, 'slots' => [], 'outcome' => null, 'closed' => null, 'next' => [], 'no_angles' => $ordered === []];
        if ($round < 1) {
            return $empty;
        }
        $available = TestingRules::available(array_keys($ordered), self::usedBefore($productId, $round));
        $slots = TestingRules::slots($available);
        $batches = CreativeBatch::where('product_id', $productId)->where('testing_round', $round)->where('status', '!=', 'discarded')->orderBy('id')
            ->get(['id', 'number', 'angle_code', 'status', 'result', 'concept']);
        $rows = $batches->map(fn ($b) => ['id' => $b->id, 'angle' => (string) $b->angle_code, 'status' => $b->status, 'code' => $b->code(),
            'verdicts' => $b->result['verdicts'] ?? [], 'concept' => $b->concept])->all();
        $pool = $rows;
        $out = [];
        foreach ($slots as $angle) {
            $i = array_key_first(array_filter($pool, fn ($b) => $b['angle'] === $angle));
            $b = $i !== null ? $pool[$i] : null;
            if ($i !== null) {
                unset($pool[$i]);
            }
            $out[] = ['angle' => $angle, 'name' => $ordered[$angle]['name'] ?? null, 'persona' => $ordered[$angle]['persona'] ?? null,
                'batch_id' => $b['id'] ?? null, 'code' => $b['code'] ?? null, 'concept' => $b['concept'] ?? null, 'label' => TestingRules::slotLabel($b)];
        }
        $closed = collect($p->testing_results ?? [])->firstWhere('round', $round);
        $usedNow = array_values(array_unique(array_merge($slots, array_column($rows, 'angle'))));

        return ['phase' => $phase, 'round' => $round, 'slots' => $out, 'outcome' => TestingRules::outcome($slots, $rows), 'closed' => $closed,
            'next' => array_values(array_diff($available, $usedNow)), 'no_angles' => $ordered === []];
    }

    /**
     * Cierra las rondas que ya tienen todos sus resultados. Con algún ganador, el producto pasa a "Iterar lo que funciona".
     *
     * @return int cuántas rondas cerró
     */
    public static function refresh(): int
    {
        $closed = 0;
        foreach (ProductProfile::where('phase', 'testing')->where('testing_round', '>', 0)->get() as $p) {
            $st = self::round($p->product_id);
            if ($st['outcome'] === null || $st['closed'] !== null) {
                continue;
            }
            $results = $p->testing_results ?? [];
            $results[] = ['round' => $st['round'], 'at' => now()->toIso8601String()] + $st['outcome'];
            $p->forceFill(['testing_results' => $results] + ($st['outcome']['won'] !== [] ? ['phase' => 'iterate', 'phase_at' => now()] : []))->save();
            $closed++;
        }

        return $closed;
    }

    /**
     * Los encargos que faltan pedir de los productos en testeo, listos para ProposalRules::plan (cada uno como lo arma
     * ProposalRules::one, más la ronda, el buyer persona del ángulo y los conceptos de los otros batches de ese ángulo).
     *
     * @param list<int> $productIds los productos en testeo con la ficha completa
     * @param array<int, array> $decisions MetaDecisions (para la ciudad de aprendizaje, si ya tiene campañas)
     * @return list<list<array>> por producto, el más viejo en testeo primero
     */
    public static function candidates(array $productIds, array $decisions): array
    {
        if ($productIds === []) {
            return [];
        }
        $names = DB::table('products')->whereIn('id', $productIds)->get(['id', 'title', 'showable_name'])->keyBy('id');
        $profiles = ProductProfile::whereIn('product_id', $productIds)->orderBy('phase_at')->get();
        $out = [];
        foreach ($profiles as $p) {
            $pid = (int) $p->product_id;
            $st = self::round($pid);
            if ($st['outcome'] !== null || $st['closed'] !== null) {
                continue;
            }
            $angles = array_column(self::angles($pid), null, 'code');
            $existing = array_values(array_filter($st['slots'], fn ($s) => $s['batch_id'] !== null));
            $missing = TestingRules::missing(array_column($st['slots'], 'angle'), array_column($existing, 'angle'));
            $city = isset($decisions[$pid]) ? ProposalRules::city($decisions[$pid]['campaigns'] ?? []) : null;
            $product = ($names[$pid]->showable_name ?? null) ?: ($names[$pid]->title ?? (string) $pid);
            $items = [];
            foreach ($missing as $m) {
                $a = $angles[$m['angle']] ?? ['name' => null, 'persona_id' => null, 'persona' => null];
                $siblings = array_values(array_filter(array_map(fn ($s) => $s['angle'] === $m['angle'] ? $s['concept'] : null, $existing)));
                $items[] = [
                    'product_id' => $pid, 'product' => $product, 'kind' => $m['n'] === 1 ? 'new_angle' : 'new_concept', 'angle_code' => $m['angle'],
                    'reference_id' => null, 'reference' => null, 'reference_type' => null, 'city_id' => $city['city_id'] ?? null, 'city' => $city['city'] ?? null,
                    'format' => 'video', 'urgency' => null, 'persona_id' => $a['persona_id'],
                    'iterate' => 'el testeo del ángulo ' . $m['angle'] . ($a['name'] ? " ({$a['name']})" : '')
                        . ($m['of'] > 1 ? ", batch {$m['n']} de {$m['of']} de este ángulo: otro concepto u otro formato, distinto del otro batch" : ''),
                    'reason' => "Testeo de {$product} (ronda {$st['round']}).",
                    'testing' => ['round' => $st['round'], 'n' => $m['n'], 'of' => $m['of'], 'siblings' => $siblings, 'persona' => $a['persona']],
                ];
            }
            if ($items) {
                $out[] = $items;
            }
        }

        return $out;
    }

    /**
     * Para las reglas de los productos que ya iteran: el orden de los ángulos de la ficha, los que perdieron en un testeo
     * (no reciben más encargos) y los que ya tuvieron algún batch.
     *
     * @return array{order: list<string>, lost: list<string>, used: list<string>}
     */
    public static function profileAngles(int $productId): array
    {
        $p = ProductProfile::where('product_id', $productId)->first();
        $lost = [];
        foreach ($p->testing_results ?? [] as $r) {
            $lost = array_merge($lost, $r['lost'] ?? []);
        }

        return [
            'order' => array_column(self::angles($productId), 'code'),
            'lost' => array_values(array_unique($lost)),
            'used' => CreativeBatch::where('product_id', $productId)->where('status', '!=', 'discarded')->whereNotNull('angle_code')->distinct()->pluck('angle_code')->all(),
        ];
    }

    /**
     * Lo de la fase para Cola y banca y la ficha: en qué va, en una frase.
     *
     * @return array{phase: string, phase_label: string, round: int, text: ?string, slots: list<array>, can_next: bool, level: string}|null
     *         null si el producto nunca se testeó y no está en testeo
     */
    public static function summary(int $productId): ?array
    {
        $st = self::round($productId);
        $last = null;
        $p = ProductProfile::where('product_id', $productId)->first();
        foreach ($p->testing_results ?? [] as $r) {
            $last = $r;
        }
        if ($st['phase'] !== 'testing' && ($last === null || now()->diffInDays($last['at'] ?? now(), true) > self::NOTICE_DAYS)) {
            return null;
        }
        $base = ['phase' => $st['phase'], 'phase_label' => TestingRules::PHASES[$st['phase']], 'round' => $st['round'], 'slots' => $st['slots'], 'can_next' => false];
        $result = fn (array $r) => implode('; ', array_filter([
            $r['won'] ? 'ganó ' . TestingRules::join($r['won']) : null,
            $r['lost'] ? 'perdió ' . TestingRules::join($r['lost']) : null,
            $r['tie'] ? 'empate en ' . TestingRules::join($r['tie']) : null,
        ]));
        if ($st['phase'] === 'testing' && $st['no_angles']) {
            return $base + ['text' => 'En testeo, pero la ficha no tiene ángulos: agrégalos para que se pidan los batches.', 'level' => 'warning'];
        }
        if ($st['phase'] === 'testing' && $st['closed'] !== null) {
            return ['can_next' => $st['next'] !== []] + $base + ['level' => 'warning', 'text' => "Testeo terminado (ronda {$st['round']}): {$result($st['closed'])}. Ningún ángulo ganó: "
                . ($st['next'] !== [] ? 'puedes probar los siguientes de la ficha (' . TestingRules::join(array_slice($st['next'], 0, 3)) . ') o revisar la oferta o la landing.' : 'agrega ángulos nuevos a la ficha o revisa la oferta o la landing.')];
        }
        if ($st['phase'] === 'testing') {
            $done = count(array_filter($st['slots'], fn ($s) => in_array(explode(' ', $s['label'])[0], ['Ganó', 'Perdió', 'Empate'], true)));

            return $base + ['level' => 'info', 'text' => "En testeo · ronda {$st['round']} · {$done} de " . count($st['slots']) . ' con resultado'];
        }

        return $base + ['level' => 'success', 'text' => "Testeo terminado: {$result($last)}. Ahora se itera lo que ganó y no se piden más encargos de lo que perdió."];
    }

    /** Los ángulos que ya salieron en algún testeo (una ronda nueva no los repite). @return list<string> */
    public static function testedAngles(int $productId): array
    {
        return self::usedBefore($productId, PHP_INT_MAX);
    }

    /** Los ángulos que ya tuvieron batches en rondas anteriores. @return list<string> */
    private static function usedBefore(int $productId, int $round): array
    {
        return CreativeBatch::where('product_id', $productId)->whereNotNull('testing_round')->where('testing_round', '<', $round)
            ->where('status', '!=', 'discarded')->whereNotNull('angle_code')->distinct()->pluck('angle_code')->all();
    }
}
