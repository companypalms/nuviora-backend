<?php

namespace App\Services\Meta\Decisions\Rules;

use App\Services\Meta\MetaMetrics;

/**
 * Las reglas del dueño para anuncios, batches y creativos (Etapa 5, paso 2a), en PHP puro. Salen de sus 14 análisis, su
 * manual corregido y la entrevista del 2026-10-30. Cada veredicto separa el dato, la lectura y la propuesta, como pide su
 * manual. Los números están en DecisionSettings; "C" es el CPA objetivo y "BE" el break-even de la ciudad.
 */
class AdRules
{
    public const STATES = [
        'no_target' => ['label' => 'Sin objetivo', 'tone' => 'default'],
        'no_data' => ['label' => 'Sin datos', 'tone' => 'default'],
        'evaluation' => ['label' => 'En evaluación', 'tone' => 'info'],
        'promising' => ['label' => 'Prometedor', 'tone' => 'success'],
        'proven' => ['label' => 'PROVEN', 'tone' => 'success'],
        'intermediate' => ['label' => 'Intermedio', 'tone' => 'warning'],
        'loser' => ['label' => 'Perdedor', 'tone' => 'error'],
        'failing' => ['label' => 'Ganador que empezó a fallar', 'tone' => 'warning'],
    ];

    public const CROSS = [
        'transversal' => 'Ganador transversal',
        'city_sensitive' => 'Sensible a ciudad',
        'loser_all' => 'Perdedor en todas las ciudades',
    ];

    public const FUNNEL = ['top' => 'Arriba del embudo', 'middle' => 'Medio del embudo', 'bottom' => 'Abajo del embudo'];

    public function __construct(private array $s)
    {
    }

    /**
     * El estado por gasto y compras (de toda la vida del anuncio o batch).
     *
     * @param string $unit "anuncio", "batch" o "creativo", para la propuesta
     * @return array{key: string, label: string, tone: string, data: string, reading: string, proposal: ?string}
     */
    public function state(float $spend, float $purchases, ?float $c, ?float $be, string $unit = 'anuncio'): array
    {
        $cpa = $purchases > 0 ? $spend / $purchases : null;
        if ($c === null || $c <= 0) {
            return $this->verdict('no_target', $this->spendText($spend, $purchases, null), 'La campaña no tiene CPA objetivo.',
                CampaignRules::NO_TARGET);
        }
        $data = $this->spendText($spend, $purchases, $c) . ($cpa !== null ? ' · CPA ' . self::money($cpa) : '')
            . ' · C ' . self::money($c) . ($be !== null ? ' · BE ' . self::money($be) : '');
        $times = $spend / $c;
        if ($cpa !== null && $purchases >= $this->s['proven_purchases'] && $cpa <= $c) {
            return $this->verdict('proven', $data, 'Ganador: ' . self::num($purchases) . ' compras con CPA debajo de C.',
                $unit === 'batch' ? 'No se toca: las iteraciones van en un batch nuevo.' : 'Mantener sin tocarlo e iterarlo en un batch nuevo.');
        }
        if ($cpa !== null && $purchases >= $this->s['promising_purchases'] && $cpa <= $c) {
            return $this->verdict('promising', $data, 'Va bien: ' . self::num($purchases) . ' compras con CPA debajo de C.', 'Mantener.');
        }
        if ($times < $this->s['no_data_cpa']) {
            return $this->verdict('no_data', $data, 'Todavía no gastó ' . self::num($this->s['no_data_cpa']) . '×C.',
                'Esperar hasta ' . self::money($c * $this->s['no_data_cpa']) . '.');
        }
        if ($purchases <= 1 && $times >= $this->s['loser_spend_cpa']) {
            return $this->verdict('loser', $data, 'Perdedor: gastó ' . self::num($times) . '×C con ' . ($purchases == 0 ? 'ninguna compra' : 'una sola compra') . '.',
                $this->offProposal($unit));
        }
        if ($purchases >= 2 && $be !== null && $cpa >= $be) {
            // .101: con pocas compras, apenas encima del BE no alcanza: puede ser mala suerte (o un BE con pocos datos)
            if ($times >= $this->s['evaluation_cap_cpa'] && !$this->clearlyAbove($cpa, $be, $purchases)) {
                return $this->verdict('evaluation', $data, 'En evaluación: CPA ' . self::num(round(($cpa / $be - 1) * 100)) . ' % encima del break-even con '
                    . self::num($purchases) . ' compras. Con menos de ' . self::num($this->s['loser_be_margin_purchases']) . ' compras se apaga solo si pasa el '
                    . self::num($this->s['loser_be_margin_pct']) . ' %.', 'Mantener; se vuelve a mirar cada día.');
            }
            if ($times >= $this->s['evaluation_cap_cpa']) {
                return $this->verdict('loser', $data, 'Perdedor: llegó a ' . self::num($times) . '×C con el CPA en el break-even o encima.', $this->offProposal($unit));
            }

            return $this->verdict('evaluation', $data, 'En evaluación, con el CPA en el break-even o encima.',
                'Darle como mucho hasta ' . self::money($c * $this->s['evaluation_cap_cpa']) . ' (' . self::num($this->s['evaluation_cap_cpa']) . '×C); si sigue igual, apagar.');
        }
        if ($purchases >= 2 && $cpa > $c) {
            return $this->verdict('intermediate', $data, 'Intermedio: CPA entre C y el break-even.', 'Mantener si aporta ventas; no escalar.');
        }

        return $this->verdict('evaluation', $data, 'En evaluación.',
            'Dejarlo correr hasta ' . self::money($c * $this->s['loser_spend_cpa']) . ' (' . self::num($this->s['loser_spend_cpa']) . '×C).');
    }

    /**
     * ¿Alguna vez fue ganador? En toda su vida, o en alguna semana (7 días seguidos) con 5+ compras y CPA ≤ C.
     *
     * @param array<string, array{spend: float, purchases: float}> $daily fecha => gasto y compras
     */
    public function hadGoodHistory(array $lifetimeState, array $daily, ?float $c, int $days = 7): bool
    {
        if ($lifetimeState['key'] === 'proven') {
            return true;
        }
        if ($c === null || !$daily) {
            return false;
        }
        ksort($daily);
        $dates = array_keys($daily);
        $start = new \DateTimeImmutable($dates[0]);
        $end = new \DateTimeImmutable(end($dates));
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $spend = 0.0;
            $purchases = 0.0;
            for ($i = 0; $i < $days; $i++) {
                $row = $daily[$d->modify("+{$i} day")->format('Y-m-d')] ?? null;
                $spend += $row['spend'] ?? 0;
                $purchases += $row['purchases'] ?? 0;
            }
            if ($purchases >= $this->s['proven_purchases'] && $spend / $purchases <= $c) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Un ganador que empezó a fallar? Fue ganador y en lo reciente (3 días, o 7 si en 3 casi no gastó) está en el
     * break-even o encima, o gastó 2×C sin comprar.
     */
    public function isFailing(bool $goodHistory, ?array $w3, ?array $w7, ?float $c, ?float $be): bool
    {
        if (!$goodHistory || $c === null) {
            return false;
        }
        $recent = ($w3['totals']['spend'] ?? 0) >= $c * $this->s['no_data_cpa'] ? $w3 : $w7;
        if (!$recent) {
            return false;
        }
        $spend = (float) ($recent['totals']['spend'] ?? 0);
        $purchases = (float) ($recent['totals']['purchases'] ?? 0);
        if ($spend < $c * $this->s['no_data_cpa']) {
            return false;
        }

        return ($purchases == 0 && $spend >= $c * $this->s['loser_spend_cpa'])
            || ($purchases > 0 && $be !== null && $spend / $purchases >= $be);
    }

    /** El veredicto de un ganador que empezó a fallar, con lo que cambió. */
    public function failingVerdict(array $lifetime, array $causes): array
    {
        $why = $causes ? implode(' · ', array_map(fn ($c) => $c['label'] . ($c['evidence'] ? " ({$c['evidence']})" : ''), $causes)) : 'sin una causa clara en los números';

        return $this->verdict('failing', $lifetime['data'], 'Fue ganador y en los últimos días está en el break-even o encima: ' . $why . '.',
            $this->failingProposal($causes));
    }

    /**
     * Qué cambió entre lo reciente (3 días) y antes (los 3 días anteriores, o toda la vida si antes no hubo datos):
     * fatiga, subasta, landing u oferta, velocidad de la landing o tracking.
     *
     * @return array<int, array{key: string, label: string, evidence: string}>
     */
    public function whatChanged(?array $now, ?array $before, ?array $lifetime, ?float $cityCpmChange = null, ?float $trackingRatio = null): array
    {
        $m = $now['metrics'] ?? [];
        $ref = ($before['totals']['impressions'] ?? 0) > 0 ? ($before['metrics'] ?? []) : ($lifetime['metrics'] ?? []);
        $change = fn (string $k) => MetaMetrics::change($m[$k] ?? null, $ref[$k] ?? null);
        $ctrKey = ($m['unique_ctr'] ?? null) !== null && ($ref['unique_ctr'] ?? null) !== null ? 'unique_ctr' : 'link_ctr';
        $freqUp = ($change('frequency') ?? 0) >= $this->s['freq_rise_pct'];
        $ctrDown = ($change($ctrKey) ?? 0) <= -$this->s['ctr_drop_pct'];
        $hookDown = ($change('hook_rate') ?? 0) <= -$this->s['ctr_drop_pct'];
        $convDown = ($change('conversion_rate') ?? 0) <= -$this->s['conv_drop_pct'];
        $ev = fn (string $k, string $label, string $kind) => ($m[$k] ?? null) !== null && ($ref[$k] ?? null) !== null
            ? "{$label} " . self::fmt($kind, $ref[$k]) . ' → ' . self::fmt($kind, $m[$k]) : null;

        $causes = [];
        if ($freqUp && ($ctrDown || $hookDown)) {
            $causes[] = ['key' => 'fatigue', 'label' => 'Fatiga', 'evidence' => implode('; ', array_filter([
                $ev('frequency', 'frecuencia', 'num'), $ctrDown ? $ev($ctrKey, $ctrKey === 'unique_ctr' ? 'CTR único' : 'CTR', 'pct') : null,
                $hookDown ? $ev('hook_rate', 'hook', 'pct') : null]))];
        }
        if ($cityCpmChange !== null && $cityCpmChange >= $this->s['cpm_rise_pct'] && !$ctrDown) {
            $causes[] = ['key' => 'auction', 'label' => 'Subasta más cara', 'evidence' => 'el CPM de toda la ciudad subió ' . self::num($cityCpmChange) . ' %'];
        }
        if (!$ctrDown && !$hookDown && $convDown) {
            $causes[] = ['key' => 'landing_offer', 'label' => 'Landing u oferta', 'evidence' => implode('; ', array_filter([
                'el anuncio engancha igual', $ev('conversion_rate', 'compra sobre visitas', 'pct')]))];
        }
        if (($m['lpv_rate'] ?? null) !== null && $m['lpv_rate'] < $this->s['visit_leak']) {
            $causes[] = ['key' => 'landing_speed', 'label' => 'Landing lenta', 'evidence' => 'solo el ' . self::num($m['lpv_rate']) . ' % de los clics llega a la página'];
        }
        if ($trackingRatio !== null && ($trackingRatio < $this->s['tracking_low_pct'] || $trackingRatio > $this->s['tracking_high_pct'])) {
            $causes[] = ['key' => 'tracking', 'label' => 'Tracking', 'evidence' => 'Meta ve el ' . self::num($trackingRatio) . ' % de los pedidos reales del producto'];
        }

        return $causes;
    }

    /**
     * Entre ciudades, el mismo creativo: ganador transversal (gana en 2 o más), sensible a ciudad (gana en unas y pierde
     * en otras) o perdedor en todas.
     *
     * @param array<string, string> $states ciudad => clave del estado
     * @return array{key: string, label: string, detail: string}|null
     */
    public function crossCity(array $states): ?array
    {
        $wins = array_keys(array_filter($states, fn ($s) => in_array($s, ['promising', 'proven'], true)));
        $losers = array_keys(array_filter($states, fn ($s) => $s === 'loser'));
        $withData = array_filter($states, fn ($s) => !in_array($s, ['no_data', 'no_target'], true));
        $key = match (true) {
            $wins && $losers => 'city_sensitive',
            count($wins) >= 2 => 'transversal',
            count($withData) >= 2 && count($losers) === count($withData) => 'loser_all',
            default => null,
        };
        if ($key === null) {
            return null;
        }
        $detail = implode(' · ', array_filter([$wins ? 'gana en ' . implode(', ', $wins) : null, $losers ? 'pierde en ' . implode(', ', $losers) : null]));

        return ['key' => $key, 'label' => self::CROSS[$key], 'detail' => $detail];
    }

    /** La parte del embudo de un creativo, por su frecuencia de 3 días. */
    public function funnel(?float $frequency): ?string
    {
        return match (true) {
            $frequency === null => null,
            $frequency < $this->s['funnel_middle_from'] => 'top',
            $frequency <= $this->s['funnel_bottom_above'] => 'middle',
            default => 'bottom',
        };
    }

    public function saturated(?float $campaignFrequency): bool
    {
        return $campaignFrequency !== null && $campaignFrequency >= $this->s['saturation_from'];
    }

    /**
     * `.80` (dueño, 2026-11-02): el video se lee por tramos, en orden; el editor recibe solo el primero flojo ("si no pasan
     * el hook, el cierre no importa"). Cada tramo se mide sobre la gente que llega a él.
     */
    public const STAGES = ['hook' => 'el hook', 'after_hook' => 'justo después del hook', 'middle' => 'el medio', 'close' => 'el cierre', 'click' => 'el clic'];

    /** `.80`: con menos que esto entrando a un tramo, no se juzga (impresiones, vistas de 3 s, al 25 %, al 75 %). */
    private const STAGE_MIN = ['hook' => 1000, 'after_hook' => 100, 'middle' => 50, 'close' => 30, 'click' => 1000];

    /** La mediana de los ganadores con la que se compara cada tramo. */
    private const STAGE_MEDIAN = ['hook' => 'hook_rate', 'after_hook' => 'hold_rate', 'middle' => 'retention_middle', 'close' => 'retention_close', 'click' => 'unique_ctr'];

    /**
     * El diagnóstico de un video (o de una imagen, sin lo de video). `.80`: por tramos (STAGES): el hook (hook rate: de los
     * que lo ven, cuántos se quedan 3 s), justo después del hook (hold rate: de los que pasan los 3 s, cuántos llegan a los
     * 15), el medio (del 25 % al 75 %), el cierre (del 75 % al final) y el clic (CTR único). Cada uno es flojo si está debajo
     * de tu mínimo (Reglas) o bastante debajo de la mediana de tus ganadores del producto; "iterate" es el primero flojo.
     * Lo que no es del video (la landing, el CPM) solo cuenta si el creativo está caro (CPA encima de C).
     *
     * @param array $w ventana con totals y metrics
     * @param array $winners mediana de los ganadores: hook_rate, hold_rate, retention_middle, retention_close, unique_ctr, conversion_rate
     * @return array{grades: array, stages: array, leaves: ?string, retention: array, watch_pct: ?float, diagnosis: array, iterate: ?string}
     */
    public function video(array $w, bool $isVideo, ?float $length, array $winners = [], ?float $cityCpm = null, ?float $cpa = null, ?float $c = null): array
    {
        $m = $w['metrics'] ?? [];
        $t = $w['totals'] ?? [];
        $share = fn (?float $a, ?float $b) => $a !== null && $b !== null && $b > 0 ? $a / $b * 100 : null;
        $enough = fn (string $stage, $base) => (float) ($base ?? 0) >= self::STAGE_MIN[$stage];
        $values = [
            'hook' => $isVideo && $enough('hook', $t['impressions'] ?? 0) ? ($m['hook_rate'] ?? null) : null,
            'after_hook' => $isVideo && $enough('after_hook', $t['video_3s_plays'] ?? 0) ? ($m['hold_rate'] ?? null) : null,
            'middle' => $isVideo && $enough('middle', $t['video_p25'] ?? 0) ? $share($t['video_p75'] ?? null, $t['video_p25'] ?? null) : null,
            'close' => $isVideo && $enough('close', $t['video_p75'] ?? 0) ? $share($t['video_p100'] ?? null, $t['video_p75'] ?? null) : null,
            'click' => $enough('click', $t['impressions'] ?? 0) ? ($m['unique_ctr'] ?? null) : null,
        ];
        $floor = ['hook' => $this->s['hook_weak'], 'after_hook' => $this->s['hold_weak'], 'middle' => $this->s['retention_body'],
            'close' => $this->s['retention_end'], 'click' => $this->s['ctr_weak']];
        $goodFrom = ['hook' => $this->s['hook_good'], 'after_hook' => $this->s['hold_good'], 'middle' => null, 'close' => null, 'click' => $this->s['ctr_good']];
        $stages = [];
        foreach (self::STAGES as $k => $label) {
            if (($v = $values[$k]) === null) {
                continue;
            }
            $median = $winners[self::STAGE_MEDIAN[$k]] ?? null;
            $stages[$k] = ['label' => $label, 'value' => round((float) $v, 1), 'median' => $median !== null ? round((float) $median, 1) : null,
                'grade' => match (true) {
                    $v < $floor[$k] => 'weak',
                    $median !== null && $v <= $median * (1 - $this->s['winners_gap_pct'] / 100) => 'weak',
                    ($goodFrom[$k] !== null && $v >= $goodFrom[$k]) || ($median !== null && $v >= $median) => 'good',
                    default => 'ok',
                }];
        }
        $grade = fn (string $k) => $stages[$k]['grade'] ?? null;
        $grades = [
            'hook' => $grade('hook'), 'hold' => $grade('after_hook'), 'middle' => $grade('middle'), 'close' => $grade('close'), 'ctr' => $grade('click'),
            'visit' => ($m['lpv_rate'] ?? null) === null ? null : ($m['lpv_rate'] < $this->s['visit_leak'] ? 'weak' : ($m['lpv_rate'] >= $this->s['visit_ok'] ? 'good' : 'ok')),
            'conversion' => ($m['conversion_rate'] ?? null) !== null && ($winners['conversion_rate'] ?? null) !== null
                ? ($m['conversion_rate'] <= $winners['conversion_rate'] * (1 - $this->s['winners_gap_pct'] / 100) ? 'weak' : 'good') : null,
        ];
        $retention = $isVideo ? [
            'reach_25' => $share($t['video_p25'] ?? null, $t['video_plays'] ?? null),
            'from_25_to_75' => $values['middle'],
            'from_75_to_end' => $values['close'],
        ] : [];
        $watchPct = $isVideo && $length && ($m['avg_watch_time'] ?? null) !== null ? $m['avg_watch_time'] / $length * 100 : null;
        $grades['watch'] = $watchPct === null ? null : ($watchPct >= $this->s['watch_good'] ? 'good' : ($watchPct < $this->s['watch_weak'] ? 'weak' : 'ok'));

        $first = array_key_first(array_filter($stages, fn ($st) => $st['grade'] === 'weak'));
        $leaves = ['after_hook' => 'after_hook', 'middle' => 'body', 'close' => 'end'][$first] ?? null;
        $expensive = $c !== null && ($cpa === null || $cpa > $c);
        $dx = [];
        foreach ($stages as $k => $st) {
            if ($st['grade'] === 'weak') {
                $dx[] = self::stageText($k, $st);
            }
        }
        $iterate = $first;
        if ($first === null && $expensive && in_array($grades['ctr'], ['good', 'ok'], true) && $grades['conversion'] === 'weak') {
            $dx[] = 'Engancha y hace clic pero no compra: revisar que la landing hable de lo mismo que el anuncio (probar una landing del ángulo, sin cambiar el creativo).';
            $iterate = 'landing';
        }
        if ($grades['visit'] === 'weak') {
            $dx[] = 'Solo el ' . self::num($m['lpv_rate']) . ' % de los clics llega a la página: revisar la velocidad de la landing.';
        }
        if ($isVideo && $cityCpm !== null && ($m['cpm'] ?? null) !== null && $m['cpm'] >= $cityCpm * 1.3 && $expensive) {
            $dx[] = 'CPM ' . self::money($m['cpm']) . ', mucho más alto que el de la ciudad (' . self::money($cityCpm) . '), y no compensa: probar el concepto en imagen.';
            $iterate ??= 'format';
        }

        return ['grades' => $grades, 'stages' => $stages, 'leaves' => $leaves, 'retention' => $retention,
            'watch_pct' => $watchPct !== null ? round($watchPct, 1) : null, 'diagnosis' => $dx, 'iterate' => $iterate];
    }

    /** `.80`: un tramo flojo, para el dueño: "El medio pierde gente: del 25 % al 75 % sigue solo el 37 % (tus ganadores: 52 %)." */
    public static function stageText(string $k, array $st): string
    {
        $v = self::num($st['value']) . ' %';
        $vs = $st['median'] !== null ? ' (tus ganadores: ' . self::num($st['median']) . ' %)' : '';

        return match ($k) {
            'hook' => "El hook está flojo: solo el {$v} de los que lo ven se queda 3 segundos{$vs}.",
            'after_hook' => "Se van justo después del hook: de los que pasan los 3 segundos, solo el {$v} llega a los 15 (hold rate){$vs}.",
            'middle' => "El medio pierde gente: del 25 % al 75 % del video sigue solo el {$v}{$vs}.",
            'close' => "El cierre pierde gente: del 75 % al final sigue solo el {$v}{$vs}.",
            default => "Poca gente hace clic: CTR único {$v}{$vs}.",
        };
    }

    /** Mediana de una lista (null si está vacía). */
    public static function median(array $values): ?float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));
        if (!$values) {
            return null;
        }
        sort($values);
        $n = count($values);

        return $n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    public static function money(?float $v): string
    {
        return $v === null ? 'N/A' : '$' . number_format($v, 2, ',', '.');
    }

    public static function num(?float $v): string
    {
        return $v === null ? 'N/A' : rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');
    }

    private static function fmt(string $kind, ?float $v): string
    {
        return match ($kind) {
            'pct' => self::num($v) . ' %',
            'money' => self::money($v),
            default => $v === null ? 'N/A' : number_format($v, 2, ',', '.'),
        };
    }

    private function grade(?float $value, string $key, ?float $winnersMedian): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value < $this->s["{$key}_weak"]) {
            return 'weak';
        }
        if ($winnersMedian !== null && $value <= $winnersMedian * (1 - $this->s['winners_gap_pct'] / 100)) {
            return 'weak';
        }

        return $value >= $this->s["{$key}_good"] ? 'good' : 'ok';
    }

    /**
     * `.86` (dueño, 2026-11-03: CRE003-FLEX-FAS-BATCH003 en Carabobo era "Intermedio" por toda su vida, pero llevaba días
     * sin vender): un creativo intermedio o en evaluación que en los últimos 3 días cerrados gastó 2×C o más en una ciudad
     * sin compras, o con el CPA en el break-even o encima, es perdedor ahí aunque su vida completa esté bien. Los ganadores
     * siguen con su regla (si se caen, "ganador que falla"). `.101`: "encima" con el margen (clearlyAbove). PHP puro.
     *
     * @param array|null $recent los totales de los 3 días en esa ciudad (spend, purchases)
     * @param array $state el veredicto de toda su vida en esa ciudad
     */
    public function recentLoser(?array $recent, array $state, ?float $c, ?float $be): ?array
    {
        if (!$c || $c <= 0 || !in_array($state['key'] ?? null, ['intermediate', 'evaluation'], true)) {
            return null;
        }
        $spend = (float) ($recent['spend'] ?? 0);
        $purchases = (float) ($recent['purchases'] ?? 0);
        $losing = $purchases == 0 || ($be !== null && $this->clearlyAbove($spend / $purchases, $be, $purchases));
        if ($spend < $c * $this->s['loser_spend_cpa'] || !$losing) {
            return null;
        }
        $data = '3 días: ' . lcfirst($this->spendText($spend, $purchases, $c)) . ($purchases > 0 ? ' · CPA ' . self::money($spend / $purchases) : '')
            . ' · C ' . self::money($c) . ($be !== null ? ' · BE ' . self::money($be) : '');

        return $this->verdict('loser', $data, 'Perdedor en los últimos 3 días: gastó ' . self::num($spend / $c) . '×C '
            . ($purchases == 0 ? 'sin compras' : 'con el CPA ' . self::num(round(($spend / $purchases / $be - 1) * 100)) . ' % encima del break-even')
            . ' (en toda su vida: ' . mb_strtolower($state['label']) . ').',
            $this->offProposal('creativo'));
    }

    /**
     * `.110` (dueño, 2026-11-06: ZenPulse CREATIVO 7 salió "perdedor en todas las ciudades" y en Distrito Capital vendía: 2
     * compras con CPA $2,47 y C $5,76): un creativo que en una ciudad todavía no gastó 1×C pero ya vende no está "sin datos":
     * va bien por ahora (con menos de 1×C su CPA siempre queda debajo de C) y cuenta entre ciudades. Solo para creativos.
     */
    public function sellingEarly(array $state, float $spend, float $purchases): ?array
    {
        if (($state['key'] ?? null) !== 'no_data' || $purchases < 1) {
            return null;
        }

        return $this->verdict('evaluation', $state['data'], 'Va bien por ahora: ' . self::num($purchases) . ($purchases == 1 ? ' compra' : ' compras')
            . ' con CPA ' . self::money($spend / $purchases) . ', debajo de C. Todavía gastó menos de ' . self::num($this->s['no_data_cpa']) . '×C.',
            'Mantener; se vuelve a mirar cada día.');
    }

    /**
     * `.101` (dueño, 2026-11-04: el CRE002 de Termafit se apagó en Aragua con el CPA 9 % encima del BE y 10 compras): un CPA
     * en el BE o encima pierde de verdad si pasa el margen (20 %) o si ya tiene compras de sobra (20) para no ser suerte.
     */
    private function clearlyAbove(float $cpa, float $be, float $purchases): bool
    {
        return $cpa >= $be * (1 + $this->s['loser_be_margin_pct'] / 100) || $purchases >= $this->s['loser_be_margin_purchases'];
    }

    private function spendText(float $spend, float $purchases, ?float $c): string
    {
        return 'Gastó ' . self::money($spend) . ($c ? ' (' . self::num($spend / $c) . '×C)' : '') . ' · ' . self::num($purchases)
            . ($purchases == 1 ? ' compra' : ' compras');
    }

    private function offProposal(string $unit): string
    {
        return match ($unit) {
            'batch' => 'Apagar el batch.',
            'creativo' => 'Apagarlo en esta ciudad.',
            default => 'Apagar este anuncio.',
        };
    }

    private function failingProposal(array $causes): string
    {
        $keys = array_column($causes, 'key');

        return match (true) {
            in_array('tracking', $keys, true) => 'Antes de tocar nada, revisar el píxel o la API de conversiones: Meta no está viendo las compras.',
            in_array('fatigue', $keys, true) => 'No apagarlo todavía: hacen falta iteraciones con otro hook o formato (mismo concepto) y creativos nuevos de arriba del embudo.',
            in_array('auction', $keys, true) => 'No es el creativo: la subasta está más cara en toda la ciudad. Vigilar sin apagar.',
            in_array('landing_offer', $keys, true) => 'El anuncio sigue enganchando: revisar la landing o la oferta, sin cambiar el creativo.',
            in_array('landing_speed', $keys, true) => 'Revisar la velocidad de la landing.',
            default => 'Vigilar 1 o 2 días más antes de apagarlo y revisarlo en Ads Manager.',
        };
    }

    private function verdict(string $key, string $data, string $reading, ?string $proposal): array
    {
        return ['key' => $key, 'label' => self::STATES[$key]['label'], 'tone' => self::STATES[$key]['tone'],
            'data' => $data, 'reading' => $reading, 'proposal' => $proposal];
    }
}
