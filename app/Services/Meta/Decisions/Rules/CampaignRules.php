<?php

namespace App\Services\Meta\Decisions\Rules;

/**
 * Qué hacer con cada campaña (producto × ciudad, CBO), con montos, y lo del producto: el precio, la urgencia y el
 * trabajo siguiente (Etapa 5, paso 2b). PHP puro, con las reglas que decidió el dueño el 2026-10-30:
 * - escalar con CPA 3D ≤ C, 10+ compras en 3D, stock, 7D coherente y sin saturar: +20 % si va un 25 % o más debajo de C,
 *   si no +10 %; como mucho un aumento por día;
 * - entre C y BE: mantener, podar y meter creativos;
 * - en el BE o encima: intervenir (podar y lanzar); 2 días cerrados después, −20 %; 5 días, pausar la ciudad; después el
 *   último test (1,5×C por día, 3 días) y, si falla, recomendar dejar el producto;
 * - nunca presupuesto y creativos el mismo día; la IA solo recomienda: el sistema no toca Meta.
 */
class CampaignRules
{
    public const ACTIONS = [
        'no_target' => ['label' => 'Sin objetivo', 'tone' => 'default'],
        'wait' => ['label' => 'Esperar', 'tone' => 'default'],
        'none' => ['label' => 'Nada', 'tone' => 'default'],
        'scale' => ['label' => 'Subir', 'tone' => 'success'],
        'hold' => ['label' => 'Mantener', 'tone' => 'info'],
        'hold_prune' => ['label' => 'Mantener y podar', 'tone' => 'info'],
        'intervene' => ['label' => 'Intervenir', 'tone' => 'warning'],
        'decrease' => ['label' => 'Bajar', 'tone' => 'warning'],
        'pause_city' => ['label' => 'Pausar la ciudad', 'tone' => 'error'],
        'last_test' => ['label' => 'Último test', 'tone' => 'warning'],
        'drop' => ['label' => 'Dejar el producto aquí', 'tone' => 'error'],
    ];

    public const URGENCY = [1 => 'U1 · urgente', 2 => 'U2 · atención', 3 => 'U3 · escalando', 4 => 'U4 · sano', 5 => 'U5 · sin trabajo'];
    public const BATCHES = [1 => '2–3', 2 => '1–2', 3 => '1', 4 => '0–1', 5 => '0'];

    public function __construct(private array $s)
    {
    }

    /**
     * La acción de una campaña activa.
     *
     * @param array $in c, be, mode, budget, spend3, purchases3, cpa3, cpa7, freq3, stock_ok, stock_note,
     *                  increased_24h, budget_changed_today, new_ads_today, streak_days, intervention (fecha o null),
     *                  closed_since_intervention, decreased_since_intervention, history_since, last_test (null o
     *                  ['days' => n, 'cpa' => x]), prune (nombres de anuncios perdedores), new_batches, bank_ready (los B####
     *                  en banca, listos para lanzar)
     * @return array{key: string, label: string, tone: string, data: string, reading: string, proposal: ?string, from: ?float, to: ?float}
     */
    public function action(array $in): array
    {
        $c = $in['c'] ?? null;
        $be = $in['be'] ?? null;
        $mode = $in['mode'] ?? 'normal';
        $budget = $in['budget'] ?? null;
        if (in_array($mode, ['paused', 'abandoned'], true)) {
            return $this->out('none', '', $mode === 'paused' ? 'El producto está en pausa.' : 'El producto está abandonado.', 'No escalar ni pedir creativos.');
        }
        if (!$c) {
            return $this->out('no_target', '', 'La campaña no tiene CPA objetivo.', 'Clasifícala o recalcula el CPA en Finanzas.');
        }
        $spend = (float) ($in['spend3'] ?? 0);
        $purchases = (float) ($in['purchases3'] ?? 0);
        $cpa = $purchases > 0 ? $spend / $purchases : null;
        $data = '3 días: gasto ' . AdRules::money($spend) . ' · ' . AdRules::num($purchases) . ' compras' . ($cpa !== null ? ' · CPA ' . AdRules::money($cpa) : '')
            . ' · C ' . AdRules::money($c) . ($be ? ' · BE ' . AdRules::money($be) : '') . ($budget ? ' · presupuesto ' . AdRules::money($budget) . '/día' : '');
        $prune = $in['prune'] ?? [];
        $pruneText = $prune ? ' Apagar: ' . implode(', ', array_slice($prune, 0, 6)) . (count($prune) > 6 ? '…' : '') . '.' : '';

        // El último test en curso: si después de sus días sigue en el BE o encima, se recomienda dejar el producto aquí
        if ($lt = $in['last_test'] ?? null) {
            if ($lt['days'] >= $this->s['last_test_days'] && $be && ($lt['cpa'] === null || $lt['cpa'] >= $be)) {
                return $this->out('drop', $data, 'El último test (' . (int) $lt['days'] . ' días) también quedó en el break-even o encima.',
                    'Recomendación: dejar el producto en esta ciudad. Lo decides tú.');
            }
            if ($lt['days'] < $this->s['last_test_days']) {
                return $this->out('wait', $data, 'Último test en curso (' . (int) $lt['days'] . ' de ' . (int) $this->s['last_test_days'] . ' días).', 'No tocar nada hasta terminarlo.');
            }
        }
        if ($spend < $c * $this->s['no_data_cpa']) {
            return $this->out('wait', $data, 'En 3 días gastó menos de ' . AdRules::num($this->s['no_data_cpa']) . '×C: todavía no alcanza para decidir.', null);
        }

        // En el break-even o encima
        if ($be && (($cpa !== null && $cpa >= $be) || ($cpa === null && $spend >= $c * $this->s['loser_spend_cpa']))) {
            if ($mode === 'liquidation') {
                return $this->out('hold_prune', $data, 'En liquidación y en el break-even o encima.', 'Dejar solo los anuncios cerca o debajo del BE; sin creativos nuevos.' . $pruneText);
            }
            $since = $in['intervention'] ?? null;
            $days = (int) ($in['closed_since_intervention'] ?? 0);
            $streak = (int) ($in['streak_days'] ?? 0);
            if ($since === null) {
                $history = ($in['history_since'] ?? null) === null ? ' (todavía sin historial de presupuestos y estados)' : '';
                if ($in['budget_changed_today'] ?? false) {
                    return $this->out('wait', $data, 'En el break-even o encima, pero hoy ya se tocó el presupuesto.', 'Los creativos, mañana: nunca presupuesto y creativos el mismo día.');
                }

                return $this->out('intervene', $data, 'En el break-even o encima' . ($streak ? " hace {$streak} " . ($streak === 1 ? 'día' : 'días') : '') . $history . '.',
                    'Intervenir: podar y lanzar ' . (($in['bank_ready'] ?? []) ? $in['bank_ready'][0] . ' de la banca' : 'un batch nuevo') . '. No tocar el presupuesto hoy.' . $pruneText);
            }
            if ($days >= $this->s['pause_after_days']) {
                $test = $c * $this->s['last_test_budget_cpa'];

                return $this->out('pause_city', $data, "{$days} días cerrados en el break-even o encima desde la primera intervención.",
                    'Pausar la ciudad. Después, el último test: un batch nuevo con ' . AdRules::money($test) . ' por día durante ' . (int) $this->s['last_test_days'] . ' días.',
                    $budget, null);
            }
            if ($days >= $this->s['decrease_after_days'] && !($in['decreased_since_intervention'] ?? false)) {
                if ($in['new_ads_today'] ?? false) {
                    return $this->out('wait', $data, "Sigue en el break-even o encima {$days} días después de intervenir, pero hoy entraron creativos nuevos.",
                        'Bajar el presupuesto mañana: nunca presupuesto y creativos el mismo día.');
                }
                $to = $budget ? round($budget * (1 - $this->s['decrease_pct'] / 100), 2) : null;

                return $this->out('decrease', $data, "Sigue en el break-even o encima {$days} días cerrados después de intervenir.",
                    'Bajar ' . AdRules::num($this->s['decrease_pct']) . ' %' . ($to ? ': ' . AdRules::money($budget) . ' → ' . AdRules::money($to) : '') . '.', $budget, $to);
            }

            return $this->out('wait', $data, "Intervenida hace {$days} " . ($days === 1 ? 'día cerrado' : 'días cerrados') . '.',
                ($in['decreased_since_intervention'] ?? false) ? 'Ya se bajó el presupuesto: esperar. A los ' . (int) $this->s['pause_after_days'] . ' días, pausar la ciudad.'
                    : 'Esperar: si sigue igual ' . (int) $this->s['decrease_after_days'] . ' días cerrados, bajar ' . AdRules::num($this->s['decrease_pct']) . ' %.');
        }

        // Entre C y BE
        if ($cpa === null || $cpa > $c) {
            if ($mode === 'liquidation') {
                return $this->out('hold', $data, 'En liquidación: rentable, pero no se escala.', 'Mantener.' . $pruneText);
            }

            return $this->out('hold_prune', $data, 'Rentable pero no escalable: CPA entre C y el break-even.', 'Mantener, podar los caros y meter creativos.' . $pruneText);
        }

        // Debajo de C: ¿se puede escalar?
        $block = match (true) {
            $mode === 'liquidation' => 'En liquidación: no se escala.',
            $purchases < $this->s['scale_min_purchases'] => 'Va bien, pero con poco volumen: ' . AdRules::num($purchases) . ' compras en 3 días (hacen falta ' . (int) $this->s['scale_min_purchases'] . ').',
            ($in['stock_ok'] ?? true) === false => 'Va bien, pero no hay stock para escalar' . (($in['stock_note'] ?? null) ? ': ' . $in['stock_note'] : '') . '.',
            $be && ($in['cpa7'] ?? null) !== null && $in['cpa7'] > $be => 'Los 7 días no acompañan (CPA 7D ' . AdRules::money($in['cpa7']) . ', encima del BE).',
            ($in['freq3'] ?? null) !== null && $in['freq3'] >= $this->s['saturation_from'] => 'Va bien, pero la campaña está saturada (frecuencia ' . AdRules::num($in['freq3']) . '): primero creativos de arriba del embudo.',
            (bool) ($in['increased_24h'] ?? false) => 'Ya se subió el presupuesto en las últimas 24 horas.',
            (bool) ($in['new_ads_today'] ?? false) => 'Hoy entraron creativos nuevos: el presupuesto, mañana.',
            default => null,
        };
        if ($block !== null) {
            return $this->out('hold', $data, $block, 'Mantener.');
        }
        $big = $cpa <= $c * (1 - $this->s['scale_big_below_pct'] / 100);
        $pct = $big ? $this->s['scale_big_pct'] : $this->s['scale_small_pct'];
        $to = $budget ? round($budget * (1 + $pct / 100), 2) : null;

        return $this->out('scale', $data, ($big ? 'Muy por debajo de C' : 'Debajo de C') . ' con ' . AdRules::num($purchases) . ' compras en 3 días.',
            'Subir ' . AdRules::num($pct) . ' %' . ($to ? ': ' . AdRules::money($budget) . ' → ' . AdRules::money($to) : '') . '. Vigilar CPA y frecuencia.', $budget, $to);
    }

    /**
     * Días cerrados seguidos (hasta ayer) con el CPA de 3 días en el break-even o encima.
     *
     * @param array<string, array{spend: float, purchases: float}> $daily fecha => gasto y compras de la campaña
     * @return array{days: int, start: ?string}
     */
    public static function streak(array $daily, float $be, string $yesterday, int $max = 30): array
    {
        $days = 0;
        $start = null;
        $day = new \DateTimeImmutable($yesterday);
        for ($i = 0; $i < $max; $i++) {
            $spend = 0.0;
            $purchases = 0.0;
            for ($k = 0; $k < 3; $k++) {
                $row = $daily[$day->modify("-{$k} day")->format('Y-m-d')] ?? null;
                $spend += $row['spend'] ?? 0;
                $purchases += $row['purchases'] ?? 0;
            }
            $above = $spend > 0 && ($purchases == 0 || $spend / $purchases >= $be);
            if (!$above) {
                break;
            }
            $days++;
            $start = $day->format('Y-m-d');
            $day = $day->modify('-1 day');
        }

        return ['days' => $days, 'start' => $start];
    }

    /**
     * El precio mínimo: con el CPA de Meta de hoy, el precio con el que todavía queda la utilidad del dueño después de
     * los gastos fijos (PHP puro). Sale de la versión del CPA de finanzas: lo cobrado y los costos por entrega y las
     * entregas por compra de Meta. Bajar el precio baja lo cobrado por entrega en lo mismo.
     *
     * @param array $v revenue, variable_costs, cogs, orders_delivered, purchases, fixed_pct, target_type, target_pct
     */
    public static function minPrice(array $v, float $price, float $cpa): ?float
    {
        $delivered = (float) ($v['orders_delivered'] ?? 0);
        $purchases = (float) ($v['purchases'] ?? 0);
        if ($delivered <= 0 || $purchases <= 0 || $price <= 0) {
            return null;
        }
        $perPurchase = $delivered / $purchases;           // entregas por cada compra de Meta
        $r = (float) $v['revenue'] / $delivered;          // lo cobrado por entrega (con el upsell)
        $var = (float) $v['variable_costs'] / $delivered; // costos variables por entrega
        $fixed = (float) ($v['fixed_pct'] ?? 0) / 100;
        $m = (float) ($v['target_pct'] ?? 0) / 100;
        if (($v['target_type'] ?? 'sales') === 'cost') {
            $rMin = ($cpa / $perPurchase + $var + (float) ($v['cogs'] ?? 0) / $delivered * $m) / (1 - $fixed);
        } else {
            if ($fixed + $m >= 1) {
                return null;
            }
            $rMin = ($cpa / $perPurchase + $var) / (1 - $fixed - $m);
        }

        return round($price - ($r - $rMin), 2);
    }

    /**
     * La opción de bajar el precio, para ofrecerla junto al escalado (nunca las dos el mismo día; elige el dueño).
     *
     * @return array{text: string, price: float, to: float, min: float}|null
     */
    public function priceOption(?float $price, ?float $min, bool $eligible): ?array
    {
        if (!$eligible || !$price || $min === null || $price - $min < $this->s['price_step']) {
            return null;
        }
        $to = round($price - $this->s['price_step'], 2);

        return ['price' => $price, 'to' => $to, 'min' => $min,
            'text' => 'O probar bajar el precio ' . AdRules::money($this->s['price_step']) . ': ' . AdRules::money($price) . ' → ' . AdRules::money($to)
                . ' (con el CPA de hoy, tu utilidad aguanta hasta ' . AdRules::money($min) . '). Nunca el mismo día que el presupuesto; eliges tú.'];
    }

    /** Después de cambiar el precio: si quedó debajo del mínimo con el CPA de hoy, volver. */
    public function priceWatch(?array $change, ?float $min): ?string
    {
        if (!$change) {
            return null;
        }
        $text = 'El precio cambió hace poco (' . AdRules::money($change['from']) . ' → ' . AdRules::money($change['to']) . '): vigilar 3 días el CPA, la efectividad y el upsell.';
        if ($min !== null && $change['to'] < $change['from'] && $change['to'] < $min) {
            $text .= ' Con el CPA de hoy queda debajo de tu utilidad (mínimo ' . AdRules::money($min) . '): volver al precio anterior.';
        }

        return $text;
    }

    /**
     * Los mínimos de gasto de los ad sets contra el presupuesto de la campaña, y el presupuesto para los batches nuevos.
     *
     * @return string[]
     */
    public function budgetNotes(?float $budget, float $mins, int $newBatches, ?float $c): array
    {
        $notes = [];
        if ($budget && $mins > 0) {
            $share = $mins / $budget * 100;
            $notes[] = match (true) {
                $share > $this->s['mins_review_pct'] => 'Los mínimos de los ad sets son el ' . AdRules::num($share) . ' % del presupuesto: demasiado (más del ' . AdRules::num($this->s['mins_review_pct']) . ' %).',
                $share > $this->s['mins_ok_pct'] => 'Los mínimos de los ad sets son el ' . AdRules::num($share) . ' % del presupuesto: revisar.',
                $share < $this->s['mins_low_pct'] && $newBatches > 0 => 'Los mínimos de los ad sets son solo el ' . AdRules::num($share) . ' % del presupuesto: los batches nuevos pueden no gastar.',
                default => null,
            } ?? '';
        }
        if ($budget && $c && $newBatches > 0) {
            $min = $c * $newBatches * $this->s['startup_min_mult'];
            $max = $c * $newBatches * $this->s['startup_max_mult'];
            if ($budget < $min) {
                $notes[] = "Para {$newBatches} " . ($newBatches === 1 ? 'batch nuevo' : 'batches nuevos') . ' el presupuesto debería estar entre ' . AdRules::money($min) . ' y ' . AdRules::money($max)
                    . ' (≈' . AdRules::money($c) . ' de mínimo por batch nuevo).';
            }
        }

        return array_values(array_filter($notes));
    }

    /**
     * La urgencia de un producto (U1 a U5) y cuántos batches pedir.
     *
     * @param array $p mode, cities (claves de acción), healthy (creativos ganadores sanos), failing (ganadores que fallan),
     *                 stock_all_out (bool)
     * @return array{level: int, label: string, batches: string, reasons: string[]}
     */
    public function urgency(array $p): array
    {
        $actions = $p['cities'] ?? [];
        $reasons = [];
        $level = match (true) {
            in_array($p['mode'] ?? 'normal', ['paused', 'abandoned'], true) => 5,
            (bool) ($p['stock_all_out'] ?? false) => 5,
            (bool) array_intersect($actions, ['intervene', 'decrease', 'pause_city', 'last_test', 'drop']) || (int) ($p['healthy'] ?? 0) === 0 && $actions => 1,
            in_array('hold_prune', $actions, true) || (int) ($p['failing'] ?? 0) > 0 => 2,
            in_array('scale', $actions, true) => 3,
            default => 4,
        };
        if ($level === 5) {
            $reasons[] = ($p['stock_all_out'] ?? false) ? 'Sin stock' : 'Producto en pausa o abandonado';
        }
        if (array_intersect($actions, ['intervene', 'decrease', 'pause_city', 'last_test', 'drop'])) {
            $reasons[] = 'Ciudades en el break-even o encima';
        }
        if ((int) ($p['healthy'] ?? 0) === 0 && $actions && $level < 5) {
            $reasons[] = 'Sin creativos ganadores sanos';
        }
        if ((int) ($p['failing'] ?? 0) > 0) {
            $reasons[] = $p['failing'] . ' ' . ($p['failing'] == 1 ? 'ganador que empieza a fallar' : 'ganadores que empiezan a fallar');
        }
        $batches = ($p['mode'] ?? 'normal') === 'liquidation' ? '0' : self::BATCHES[$level];

        return ['level' => $level, 'label' => self::URGENCY[$level], 'batches' => $batches, 'reasons' => $reasons];
    }

    /**
     * Qué producir: iteraciones de un ganador, un concepto nuevo o un ángulo nuevo, y creativos de arriba del embudo si
     * las campañas están saturadas.
     *
     * @param array $p mode, winner (['label', 'iterate']|null), saturated (ciudades), main_angle, failed_batches_in_angle,
     *                 bank (los B#### aprobados sin testear), needed (lo que se pedía antes de restar la banca)
     * @return string[]
     */
    public function nextWork(array $p, string $batches): array
    {
        $bank = $p['bank'] ?? [];
        if ($batches === '0') {
            return [match (true) {
                ($p['mode'] ?? '') === 'liquidation' => 'En liquidación: sin creativos nuevos.',
                $bank !== [] && ($p['needed'] ?? '0') !== '0' => 'La banca alcanza: ' . implode(', ', $bank) . ' ' . (count($bank) === 1 ? 'está aprobado' : 'están aprobados') . ' sin testear.',
                default => 'Sin trabajo para este producto.',
            }];
        }
        $out = [];
        if ($w = $p['winner'] ?? null) {
            $out[] = 'Iteraciones de ' . $w['label'] . ': el mismo concepto, cambiando ' . ($w['iterate'] ?? 'el hook') . '.';
        } elseif (($p['failed_batches_in_angle'] ?? 0) >= 2 && ($p['main_angle'] ?? null)) {
            $out[] = 'Un ángulo nuevo: ' . $p['main_angle'] . ' ya falló en ' . $p['failed_batches_in_angle'] . ' batches.';
        } else {
            $out[] = 'Un concepto nuevo' . (($p['main_angle'] ?? null) ? ' dentro de ' . $p['main_angle'] : '') . '.';
        }
        if ($p['saturated'] ?? []) {
            $out[] = 'Creativos de arriba del embudo: frecuencia alta en ' . implode(', ', $p['saturated']) . '.';
        }

        return $out;
    }

    private function out(string $key, string $data, string $reading, ?string $proposal, ?float $from = null, ?float $to = null): array
    {
        $label = self::ACTIONS[$key]['label'];
        if ($key === 'scale' || $key === 'decrease') {
            $label .= $from && $to ? ' ' . AdRules::money($from) . ' → ' . AdRules::money($to) : '';
        }

        return ['key' => $key, 'label' => $label, 'tone' => self::ACTIONS[$key]['tone'], 'data' => $data, 'reading' => $reading,
            'proposal' => $proposal, 'from' => $from, 'to' => $to];
    }
}
