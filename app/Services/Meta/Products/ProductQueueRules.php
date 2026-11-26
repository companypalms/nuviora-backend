<?php

namespace App\Services\Meta\Products;

/**
 * `.154` (dueño, 2026-11-17: "quiero tener una banca de productos por montar… que yo vaya montando la estrategia de algunos
 * productos, la imagen, etcétera, y el sistema automáticamente se lo vaya pasando al editor líder… siempre dándole prioridad
 * a los batch de los productos que están activos actualmente, pero en cuanto tenga un chance, envía cada uno de esos
 * productos por orden de cola"). PHP puro. Lo que eligió:
 * - "Chance" = ninguna ciudad de un producto activo necesita un batch para hoy y no hay encargos atrasados.
 * - Uno a la vez: el siguiente sale cuando el equipo entregó a la banca los batches del testeo del anterior (aunque el dueño
 *   todavía no lo haya lanzado).
 * - La misión semanal de producto nuevo usa la cola: el producto de la misión sale primero.
 * - Uno al que le falta algo (la ficha, los ángulos, o está parado) se salta: sale el siguiente.
 */
final class ProductQueueRules
{
    /**
     * Lo que falta para que salga el siguiente de la cola (vacío: sale).
     *
     * @param array{need_today: list<string>, late: int, building: ?array{product: string, delivered: int, of: int}, next: ?int, waiting: int} $in
     * @return list<string>
     */
    public static function missing(array $in): array
    {
        $out = [];
        if ($in['building'] !== null) {
            $out[] = self::buildingText($in['building']);
        }
        if ($in['need_today'] !== []) {
            $out[] = 'Hoy necesitan un batch: ' . TestingRules::join($in['need_today']) . '.';
        }
        if ($in['late'] > 0) {
            $out[] = $in['late'] === 1 ? 'Hay 1 encargo atrasado.' : "Hay {$in['late']} encargos atrasados.";
        }
        if ($in['next'] === null && $in['waiting'] > 0) {
            $out[] = $in['waiting'] === 1 ? 'Al producto de la cola le falta algo: complétalo para que salga.' : 'A todos los de la cola les falta algo: complétalos para que salgan.';
        }

        return $out;
    }

    /** "El equipo está haciendo el testeo de Termafit: 1 de 3 en la banca." */
    public static function buildingText(array $b): string
    {
        return "El equipo está haciendo el testeo de {$b['product']}: {$b['delivered']} de {$b['of']} en la banca.";
    }

    /**
     * El siguiente que sale: el de la misión de producto nuevo, si está en la cola y no le falta nada; si no, el primero en
     * orden al que no le falta nada.
     *
     * @param list<array{product_id: int, missing: list<string>}> $queue en orden
     */
    public static function pick(array $queue, ?int $missionProduct): ?int
    {
        $ready = array_values(array_filter($queue, fn ($q) => $q['missing'] === []));
        foreach ($ready as $q) {
            if ($missionProduct !== null && $q['product_id'] === $missionProduct) {
                return $missionProduct;
            }
        }

        return $ready[0]['product_id'] ?? null;
    }

    /**
     * Lo que le falta a uno de la cola para poder salir.
     *
     * @param list<string> $ficha lo que le falta a la ficha (ProductProfileRules::missing)
     * @return list<string>
     */
    public static function notReady(array $ficha, bool $angles, string $mode): array
    {
        $out = [];
        if ($ficha !== []) {
            $out[] = 'En la ficha: ' . TestingRules::join(array_map('mb_strtolower', $ficha)) . '.';
        }
        if (!$angles) {
            $out[] = 'Los ángulos de venta (del más fuerte al más débil).';
        }
        if ($mode !== 'normal') {
            $out[] = match ($mode) {
                'paused' => 'Está en pausa', 'liquidation' => 'Está en liquidación', 'abandoned' => 'Está abandonado', default => 'Está parado',
            } . ': actívalo en «Pausar o activar productos» para que salga.';
        }

        return $out;
    }

    /**
     * `.177`: lo mismo que notReady, dicho para el editor líder (lo completa el dueño). null: se puede adelantar.
     *
     * @param list<string> $ficha lo que le falta a la ficha (ProductProfileRules::missing)
     */
    public static function forLeader(array $ficha, bool $angles, string $mode): ?string
    {
        $out = [];
        if ($ficha !== []) {
            $out[] = 'completar su ficha (' . TestingRules::join(array_map('mb_strtolower', $ficha)) . ')';
        }
        if (!$angles) {
            $out[] = 'poner sus ángulos de venta';
        }
        if ($mode !== 'normal') {
            $out[] = 'activarlo (' . match ($mode) {
                'paused' => 'está en pausa', 'liquidation' => 'está en liquidación', 'abandoned' => 'está abandonado', default => 'está parado',
            } . ')';
        }

        return $out === [] ? null : 'Todavía no se puede adelantar: el dueño tiene que ' . TestingRules::join($out) . '.';
    }

    /**
     * Sube o baja un producto un lugar en la cola.
     *
     * @param list<int> $ids en orden
     * @return list<int>
     */
    public static function move(array $ids, int $id, string $dir): array
    {
        $ids = array_values($ids);
        $i = array_search($id, $ids, true);
        $j = $i === false ? false : ($dir === 'up' ? $i - 1 : $i + 1);
        if ($i === false || $j < 0 || $j >= count($ids)) {
            return $ids;
        }
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];

        return $ids;
    }

    /** En cuántos batches va el testeo de un producto: los que ya están en la banca (o salieron) de los que lleva su ronda. */
    public static function progress(array $statuses, int $of): array
    {
        $delivered = count(array_filter($statuses, fn ($s) => in_array($s, ['bank', 'testing', 'tested'], true)));

        return ['delivered' => min($of, $delivered), 'of' => $of, 'done' => $of > 0 && $delivered >= $of];
    }
}
