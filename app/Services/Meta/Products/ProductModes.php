<?php

namespace App\Services\Meta\Products;

use App\Models\CreativeBatch;
use App\Models\ProductAdSetting;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Queue\QueueRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * `.99` (dueño, 2026-11-04: "es importante que si está pausado o en liquidación pierda total prioridad y se elimine de los
 * encargos al líder de editores, y si alguien lo tiene como mis tareas también se elimine"): el estado de cada producto en
 * Meta Ads y para los editores (ProductAdSetting).
 * - Activo (normal): se analiza, se escala y se le piden encargos.
 * - En pausa, en liquidación o abandonado: no se le piden encargos (ya era así) y sus encargos que todavía son tarea de
 *   alguien (sin asignar, haciéndose o por revisar) quedan en espera: no salen en ninguna lista de los editores, ni en la
 *   campanita, ni en su carga, ni cuentan como atrasados. Lo que está en la banca se queda en la banca.
 * - Al reactivarlo, esos encargos vuelven solos con una fecha nueva: la de la reserva, desde hoy (el dueño eligió "en
 *   espera, vuelven solos").
 * Lo cambia el dueño en "Pausar o activar productos" o pidiéndoselo a su asistente.
 */
final class ProductModes
{
    public const LABELS = ['normal' => 'Activo', 'paused' => 'En pausa', 'liquidation' => 'Liquidación', 'abandoned' => 'Abandonado'];

    /** Lo que significa cada uno, en una frase (para la pantalla y la tarjeta del asistente). */
    public const HELP = [
        'normal' => 'Se analiza, se escala y se le piden encargos a los editores.',
        'paused' => 'No se escala ni se le piden encargos. Úsalo si no tienes unidades (por ejemplo, sin stock del proveedor).',
        'liquidation' => 'Te quedan unidades pero no vas a reponer: no se escala ni se piden encargos; solo siguen los anuncios cerca o debajo del break-even, y se apagan al agotarse.',
        'abandoned' => 'Se dejó: no recibe trabajo.',
    ];

    /** Los estados de un encargo que todavía es tarea de alguien (asignar, hacer, entregar o revisar). */
    public const HOLD = ['approved', 'production', 'review'];

    public static function stopped(?string $mode): bool
    {
        return ($mode ?? 'normal') !== 'normal';
    }

    /** @return list<int> los productos en pausa, en liquidación o abandonados */
    public static function stoppedIds(): array
    {
        return ProductAdSetting::where('mode', '!=', 'normal')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
    }

    public static function modeOf(int $productId): string
    {
        return (string) (ProductAdSetting::where('product_id', $productId)->value('mode') ?? 'normal');
    }

    /** Sin los encargos en espera (los de un producto parado que todavía son tarea de alguien). */
    public static function withoutHeld(Builder $query): Builder
    {
        $stopped = self::stoppedIds();

        return $stopped === [] ? $query
            : $query->where(fn ($q) => $q->whereNotIn('status', self::HOLD)->orWhereNotIn('product_id', $stopped));
    }

    /**
     * Los encargos de un producto que son tarea de alguien (los que quedan en espera al pararlo).
     *
     * @return list<array{id: int, code: ?string, status: string, status_label: string, editor: ?string}>
     */
    public static function tasks(int $productId): array
    {
        return self::tasksMany([$productId])[$productId] ?? [];
    }

    /** @return array<int, list<array{id: int, code: ?string, status: string, status_label: string, editor: ?string}>> por producto */
    public static function tasksMany(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return CreativeBatch::with('editor:id,names')->whereIn('product_id', $productIds)->whereIn('status', self::HOLD)->whereNotNull('number')
            ->orderBy('number')->get(['id', 'product_id', 'number', 'status', 'editor_id'])
            ->groupBy('product_id')->map(fn ($rows) => $rows->map(fn (CreativeBatch $b) => ['id' => $b->id, 'code' => $b->code(), 'status' => $b->status,
                'status_label' => QueueRules::STATUSES[$b->status] ?? $b->status, 'editor' => $b->editor?->names])->values()->all())->all();
    }

    /**
     * "B0100 (lo tiene Eva) y B0105 (sin asignar)".
     *
     * @param list<array{code: ?string, status: string, editor: ?string}> $tasks
     */
    public static function tasksText(array $tasks): string
    {
        $parts = array_map(fn ($t) => ($t['code'] ?? 'un encargo') . ' (' . match (true) {
            $t['status'] === 'review' => 'por revisar',
            $t['editor'] !== null => "lo tiene {$t['editor']}",
            default => 'sin asignar',
        } . ')', $tasks);

        return TestingRules::join($parts);
    }

    /**
     * Cambiar el estado de un producto. La nota y los avales solo cambian si vienen. Al reactivarlo, sus encargos en espera
     * vuelven con la fecha de la reserva (desde hoy), si la que tenían ya pasó o es antes.
     *
     * @param array{note?: ?string, proof?: ?string} $extra
     * @return array{from: string, to: string, tasks: list<array>, returned: int}
     */
    public static function set(int $productId, string $mode, int $userId, array $extra = []): array
    {
        return DB::transaction(function () use ($productId, $mode, $userId, $extra) {
            $from = self::modeOf($productId);
            ProductAdSetting::updateOrCreate(['product_id' => $productId], ['mode' => $mode, 'updated_by' => $userId]
                + array_intersect_key($extra, array_flip(['note', 'proof'])));
            $tasks = self::tasks($productId);
            $returned = 0;
            if (self::stopped($from) && !self::stopped($mode)) {
                $date = now(config('app.timezone'))->addDays((int) (new DecisionSettings())->get()['reserve_due_days'])->toDateString();
                foreach (CreativeBatch::whereIn('id', array_column($tasks, 'id'))->get(['id', 'due_date']) as $b) {
                    if ($b->due_date === null || $b->due_date->toDateString() < $date) {
                        $b->forceFill(['due_date' => $date])->save();
                    }
                }
                $returned = count($tasks);
            }

            return ['from' => $from, 'to' => $mode, 'tasks' => $tasks, 'returned' => $returned];
        });
    }

    /**
     * `.99`: la banca del líder, por producto: cuántos hay en la banca, en camino, en Meta y en espera. Primero los activos con
     * menos en la banca (lo que hace falta); los parados al final.
     *
     * @param array<int, string> $names producto => nombre
     * @param array<int, array<string, int>> $counts producto => estado => cuántos
     * @param array<int, string> $modes producto => modo (sin fila: normal)
     * @return list<array{id: int, name: string, mode: string, mode_label: string, stopped: bool, bank: int, meta: int, coming: int, held: int}>
     */
    public static function bankRows(array $names, array $counts, array $modes): array
    {
        $rows = [];
        foreach ($names as $id => $name) {
            $c = $counts[$id] ?? [];
            $mode = (string) ($modes[$id] ?? 'normal');
            $stopped = self::stopped($mode);
            $open = array_sum(array_intersect_key($c, array_flip(self::HOLD)));
            $rows[] = ['id' => (int) $id, 'name' => $name, 'mode' => $mode, 'mode_label' => self::LABELS[$mode] ?? $mode, 'stopped' => $stopped,
                'bank' => (int) ($c['bank'] ?? 0), 'meta' => (int) ($c['testing'] ?? 0), 'coming' => $stopped ? 0 : $open, 'held' => $stopped ? $open : 0];
        }
        usort($rows, fn ($a, $b) => [$a['stopped'], $a['bank'], $a['name']] <=> [$b['stopped'], $b['bank'], $b['name']]);

        return $rows;
    }

    /** Lo que pasa al cambiarlo, en palabras (para el aviso de la pantalla y el asistente). */
    public static function effect(string $from, string $to, array $tasks): string
    {
        $list = $tasks === [] ? null : self::tasksText($tasks);

        return match (true) {
            $from === $to => 'Ya está así: no cambia nada.',
            self::stopped($to) && !self::stopped($from) => 'Deja de pedir encargos para este producto.'
                . ($list ? " Quedan en espera (salen de las listas de los editores): {$list}." : ''),
            !self::stopped($to) => 'Vuelve a recibir encargos cuando haga falta.'
                . ($list ? " Vuelven a las listas, con fecha nueva: {$list}." : ''),
            default => 'Sigue sin recibir encargos.',
        };
    }
}
