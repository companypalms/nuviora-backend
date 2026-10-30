<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\Present;
use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\MetaAnalyzer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cómo va la campaña de un producto en Meta Ads, con lo que dicen ahora las reglas del dueño (Etapa 5, paso 2): qué
 * hacer en cada ciudad (con montos), la urgencia, el trabajo siguiente, el precio, las alarmas y el CPA de cada día
 * contra el objetivo (C) y el break-even (BE).
 */
class MetaProductTool extends MetaTool
{
    /** Los días de la gráfica (hasta ayer: el de hoy todavía no terminó). */
    private const DAYS = 14;

    private const MAX_CREATIVES = 5;

    public function name(): string
    {
        return 'campana_producto';
    }

    public function description(): string
    {
        return 'Cómo va la publicidad en Meta Ads de un producto, con lo que dicen ahora las reglas del dueño: el CPA objetivo (C) y el break-even (BE), el gasto, las compras y el CPA de los últimos 7 días, qué hacer en cada ciudad (subir, bajar o pausar el presupuesto, con el monto; podar anuncios; esperar), la urgencia del producto y cuántos batches nuevos pedir, el trabajo siguiente para los editores, el precio, las alarmas (stock, tracking) y sus creativos principales. Úsala para "cómo va FlexiStep en Meta", "qué hago con la campaña de X", "subo o bajo el presupuesto de X".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'producto' => ['type' => 'string', 'description' => 'El nombre del producto.'],
                'grafico' => ['type' => ['boolean', 'null'], 'description' => 'La gráfica del CPA de cada día contra C y BE. null = sí.'],
            ],
            'required' => ['producto', 'grafico'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando la campaña en Meta Ads…';
    }

    public function run(array $args): ToolResult
    {
        $productId = $this->productId($args['producto'] ?? null);
        $decisions = [$productId => $this->decisionsFor($productId)];
        $p = AnalysisFacts::build($decisions, MetaAnalyzer::names($decisions))[0];
        $target = self::targets($productId, array_column($p['cities'], 'campaign_meta_id') ?: null);
        [$c, $be] = [$target['c'], $target['be']];
        $days = self::daily($productId);
        $week = array_slice($days, -7, null, true);

        $cities = [];
        $list = [];
        foreach ($p['cities'] as $city) {
            $a = $city['action'];
            $kind = $a['key'] === 'none' && $a['label'] === 'No se vende aquí' ? 'not_sold' : $a['key'];
            $cities[] = array_filter([
                'ciudad' => $city['city'], 'que_hacer' => $a['label'], 'datos' => $a['data'] ?: null, 'lectura' => $a['reading'],
                'propuesta' => $a['proposal'], 'notas' => array_merge($city['notes'], $city['budget_notes']) ?: null, 'precio' => $city['price_option'],
            ], fn ($v) => $v !== null);
            $list[] = ['text' => ($city['city'] ?? 'Campaña') . ": {$a['label']}. " . trim($a['reading'] . ' ' . ($a['proposal'] ?? '')), 'level' => self::level($kind)];
        }
        foreach ($p['alarms'] as $t) {
            $list[] = ['text' => $t, 'level' => str_starts_with($t, 'Tracking') || str_contains($t, 'sigue activa') ? 'orange' : 'yellow'];
        }
        $u = $p['urgency'];
        $data = array_filter([
            'producto' => $p['product'],
            'modo' => self::MODES[$p['mode']] ?? null,
        ] + self::targetWords($target) + [
            'c_y_be_salen_de' => match ($target['source']) { 'financial' => 'Finanzas › CPA (calculados)', 'manual' => 'puestos a mano', default => null },
            'ultimos_7_dias' => self::window(array_sum(array_column($week, 'spend')), array_sum(array_column($week, 'purchases')), $c, $be),
            'urgencia' => $u ? $u['label'] . ' · batches nuevos a pedir: ' . $u['batches'] . ($u['reasons'] ? ' · ' . implode('; ', $u['reasons']) : '') : null,
            'dinero_en_riesgo_3_dias' => $p['money_at_risk'] > 0 ? self::money($p['money_at_risk']) : null,
            'ciudades' => $cities ?: 'Ninguna campaña activa.',
            'creativos_principales' => array_map(fn ($cr) => "{$cr['label']}: {$cr['verdict']}" . ($cr['cross'] ? " ({$cr['cross']})" : ''),
                array_slice($p['creatives'], 0, self::MAX_CREATIVES)) ?: null,
            'batches_que_pierden' => array_map(fn ($b) => "{$b['name']}: {$b['verdict']}. {$b['proposal']}", $p['batches']) ?: null,
            'trabajo_siguiente' => $p['next_work'] ?: null,
            'precio' => array_merge($p['price'], array_filter([$p['price_watch']])) ?: null,
            'alarmas' => $p['alarms'] ?: null,
            'tracking_3_dias' => isset($p['tracking']['ratio']) ? 'Meta vio ' . self::purchases($p['tracking']['meta_purchases']) . ' y hubo '
                . $p['tracking']['orders'] . ' pedidos reales (' . str_replace('.', ',', (string) $p['tracking']['ratio']) . ' %)' : null,
            'cada_dia' => array_map(fn ($d, $date) => Present::bucket($date, 'day') . ': ' . self::money($d['spend']) . ', ' . self::purchases($d['purchases'])
                . ($d['purchases'] > 0 ? ', CPA ' . self::money($d['spend'] / $d['purchases']) : ''), $days, array_keys($days)),
        ], fn ($v) => $v !== null);

        $display = [];
        if ($list) {
            $display[] = ['type' => 'list', 'title' => "Qué hacer con {$p['product']}", 'items' => $list];
        }
        if (($args['grafico'] ?? null) !== false) {
            $series = [['key' => 'cpa', 'label' => 'CPA del día']];
            if ($c !== null) {
                $series[] = ['key' => 'c', 'label' => 'Objetivo (C)'];
            }
            if ($be !== null) {
                $series[] = ['key' => 'be', 'label' => 'Break-even (BE)'];
            }
            $display[] = Present::chart('line', "CPA de cada día · {$p['product']} · " . self::DAYS . ' días', 'money', $series, array_map(fn ($d, $date) => [
                'label' => Present::bucket($date, 'day'),
                'cpa' => $d['purchases'] > 0 ? round($d['spend'] / $d['purchases'], 2) : null,
                'c' => $c, 'be' => $be,
            ], $days, array_keys($days)));
        }
        $display[] = ['type' => 'link', 'label' => 'Abrir Meta Ads', 'to' => '/meta-ads'];

        return new ToolResult($data, $display);
    }

    /** @return array<string, array{spend: float, purchases: float}> por día, de hace 14 días hasta ayer */
    private static function daily(int $productId): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        [$from, $to] = [$today->subDays(self::DAYS)->toDateString(), $today->subDay()->toDateString()];
        $rows = DB::table('meta_insights_daily as d')->join('meta_campaigns as c', 'c.meta_id', '=', 'd.campaign_meta_id')
            ->where('d.level', 'campaign')->where('c.product_id', $productId)->whereBetween('d.date', [$from, $to])
            ->groupBy('d.date')->selectRaw('d.date, SUM(d.spend) spend, SUM(d.purchases) purchases')->get()
            ->keyBy(fn ($r) => substr((string) $r->date, 0, 10));
        $out = [];
        for ($d = $today->subDays(self::DAYS); $d->toDateString() <= $to; $d = $d->addDay()) {
            $k = $d->toDateString();
            $out[$k] = ['spend' => round((float) ($rows[$k]->spend ?? 0), 2), 'purchases' => (float) ($rows[$k]->purchases ?? 0)];
        }

        return $out;
    }
}
