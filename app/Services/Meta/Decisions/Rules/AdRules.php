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
                'Clasifica la campaña (producto y ciudad) para poder medirlo.');
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
     * El diagnóstico de un video (o de una imagen, sin lo de video): cada métrica contra los valores fijos y contra la
     * mediana de los ganadores del producto, dónde se va la gente y qué iterar primero.
     *
     * @param array $w ventana con totals y metrics
     * @param array $winners mediana de los ganadores: hook_rate, hold_rate, unique_ctr, conversion_rate
     * @return array{grades: array, leaves: ?string, retention: array, watch_pct: ?float, diagnosis: array, iterate: ?string}
     */
    public function video(array $w, bool $isVideo, ?float $length, array $winners = [], ?float $cityCpm = null, ?float $cpa = null, ?float $c = null): array
    {
        $m = $w['metrics'] ?? [];
        $t = $w['totals'] ?? [];
        $ctr = $m['unique_ctr'] ?? null;
        $grades = [
            'hook' => $isVideo ? $this->grade($m['hook_rate'] ?? null, 'hook', $winners['hook_rate'] ?? null) : null,
            'hold' => $isVideo ? $this->grade($m['hold_rate'] ?? null, 'hold', $winners['hold_rate'] ?? null) : null,
            'ctr' => $this->grade($ctr, 'ctr', $winners['unique_ctr'] ?? null),
            'visit' => ($m['lpv_rate'] ?? null) === null ? null : ($m['lpv_rate'] < $this->s['visit_leak'] ? 'weak' : ($m['lpv_rate'] >= $this->s['visit_ok'] ? 'good' : 'ok')),
            'conversion' => ($m['conversion_rate'] ?? null) !== null && ($winners['conversion_rate'] ?? null) !== null
                ? ($m['conversion_rate'] <= $winners['conversion_rate'] * (1 - $this->s['winners_gap_pct'] / 100) ? 'weak' : 'good') : null,
        ];
        $share = fn (?float $a, ?float $b) => $a !== null && $b !== null && $b > 0 ? $a / $b * 100 : null;
        $retention = $isVideo ? [
            'reach_25' => $share($t['video_p25'] ?? null, $t['video_plays'] ?? null),
            'from_25_to_75' => $share($t['video_p75'] ?? null, $t['video_p25'] ?? null),
            'from_75_to_end' => $share($t['video_p100'] ?? null, $t['video_p75'] ?? null),
        ] : [];
        $leaves = null;
        if ($isVideo) {
            $leaves = match (true) {
                $retention['reach_25'] !== null && $retention['reach_25'] < $this->s['retention_open'] => 'opening',
                $retention['from_25_to_75'] !== null && $retention['from_25_to_75'] < $this->s['retention_body'] => 'body',
                $retention['from_75_to_end'] !== null && $retention['from_75_to_end'] < $this->s['retention_end'] => 'end',
                default => null,
            };
        }
        $watchPct = $isVideo && $length && ($m['avg_watch_time'] ?? null) !== null ? $m['avg_watch_time'] / $length * 100 : null;
        $grades['watch'] = $watchPct === null ? null : ($watchPct >= $this->s['watch_good'] ? 'good' : ($watchPct < $this->s['watch_weak'] ? 'weak' : 'ok'));

        $dx = [];
        $iterate = null;
        $set = function (string $what) use (&$iterate) {
            $iterate ??= $what;
        };
        if ($isVideo && $grades['hook'] === 'weak' && in_array($grades['hold'], ['good', 'ok'], true)) {
            $dx[] = 'Hook flojo y hold bueno: iterar los primeros segundos y mantener el cuerpo.';
            $set('hook');
        }
        if ($isVideo && in_array($grades['hook'], ['good', 'ok'], true) && $grades['hold'] === 'weak') {
            $dx[] = 'Hook bueno y hold flojo: acortar el video.';
            $set('duration');
        }
        if ((!$isVideo || ($grades['hook'] !== 'weak' && $grades['hold'] !== 'weak')) && in_array($grades['ctr'], ['good', 'ok'], true)
            && $grades['conversion'] === 'weak') {
            $dx[] = 'Engancha y hace clic pero no compra: revisar que la landing hable de lo mismo que el anuncio (probar una landing del ángulo, sin cambiar el creativo).';
            $set('landing');
        }
        if ($grades['visit'] === 'weak') {
            $dx[] = 'Solo el ' . self::num($m['lpv_rate']) . ' % de los clics llega a la página: revisar la velocidad de la landing.';
        }
        if ($leaves === 'opening') {
            $dx[] = 'Se van al principio: solo el ' . self::num($retention['reach_25']) . ' % llega al 25 % del video.';
            $set('hook');
        } elseif ($leaves === 'body') {
            $dx[] = 'Se van en el medio: del 25 % al 75 % del video sigue solo el ' . self::num($retention['from_25_to_75']) . ' %. Revisar el ritmo o la duración.';
            $set('duration');
        } elseif ($leaves === 'end') {
            $dx[] = 'Se van al final: del 75 % al final sigue solo el ' . self::num($retention['from_75_to_end']) . ' %. Revisar el cierre (CTA).';
            $set('cta');
        }
        if ($isVideo && $cityCpm !== null && ($m['cpm'] ?? null) !== null && $m['cpm'] >= $cityCpm * 1.3 && $c !== null && ($cpa === null || $cpa > $c)) {
            $dx[] = 'CPM ' . self::money($m['cpm']) . ', mucho más alto que el de la ciudad (' . self::money($cityCpm) . '), y no compensa: probar el concepto en imagen.';
            $set('format');
        }

        return ['grades' => $grades, 'leaves' => $leaves, 'retention' => $retention, 'watch_pct' => $watchPct !== null ? round($watchPct, 1) : null,
            'diagnosis' => $dx, 'iterate' => $iterate];
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
