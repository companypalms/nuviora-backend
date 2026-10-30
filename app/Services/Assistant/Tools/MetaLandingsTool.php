<?php

namespace App\Services\Assistant\Tools;

use App\Models\LandingTask;
use App\Services\Meta\Landings\LandingReport;
use App\Services\Meta\Landings\LandingTaskRules;

/**
 * Las landings de un producto (Etapa 5, paso 6c), como en Meta Ads › Landings: cada página a la que llevan sus anuncios
 * en los últimos 7 días, con el % de visita, el % de compra y el CPA contra C, lo que dicen los números y sus encargos de
 * landing (con el test). Solo lee: pedir un cambio se hace en la pantalla.
 */
class MetaLandingsTool extends MetaTool
{
    public function name(): string
    {
        return 'landings';
    }

    public function description(): string
    {
        return 'Las landings (páginas de destino de Shopify) de un producto en Meta Ads, de los últimos 7 días: cada una con sus anuncios, clics, visitas, % de visita (si es lenta), % de compra, CPA contra C y lo que dicen los números (lenta, sin píxel, no le habla a un ángulo, cuál compra mejor con el mismo ángulo); y los encargos de landing (L####) con su estado y el resultado del test antes/después con los mismos anuncios. Sin producto, los encargos de landing de todos. Úsala para "cómo van las landings de X", "qué landing está lenta", "cómo le fue al L0100".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['producto' => ['type' => ['string', 'null'], 'description' => 'El producto. null = solo los encargos de landing de todos.']],
            'required' => ['producto'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando las landings…';
    }

    public function run(array $args): ToolResult
    {
        $productId = $this->lookup->productId($args['producto'] ?? null);
        $data = [];
        $items = [];
        if ($productId !== null) {
            $r = (new LandingReport())->forProduct($productId, 'last_7d');
            $data['periodo'] = "del {$r['from']} al {$r['to']}";
            $data['todas_sus_landings'] = self::line($r['product']);
            foreach ($r['landings'] as $l) {
                $data['landings'][] = array_filter([
                    'landing' => $l['label'] . ($l['angle_code'] ? " (ángulo {$l['angle_code']})" : ' (base)'),
                    'url' => $l['url'],
                    'anuncios' => "{$l['ads_active']} activos de {$l['ads']}",
                    'numeros' => self::line($l),
                    'lo_que_dicen_los_numeros' => array_column($l['findings'], 'text') ?: null,
                    'encargo' => $l['task'] ? ($l['task']['code'] ? "{$l['task']['code']} · {$l['task']['status_label']}" : 'propuesto, sin aprobar')
                        . ($l['task']['result'] ? ': ' . $l['task']['result'] : '') : null,
                ], fn ($v) => $v !== null && $v !== '');
                $items[] = ['text' => $l['label'] . ' · ' . self::line($l), 'level' => match ($l['level']) {
                    'red' => 'red', 'orange' => 'orange', 'yellow' => 'yellow', 'green' => 'green', default => 'pending',
                }];
            }
            if ($r['unknown']['ads'] > 0) {
                $data['nota'] = "{$r['unknown']['ads']} anuncios todavía sin landing conocida (no entran).";
            }
        }
        $tasks = LandingTask::with(['product:id,title,showable_name', 'landing', 'testLanding'])->whereIn('status', LandingTaskRules::OPEN)
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))->orderBy('id')->limit(25)->get()
            ->merge(LandingTask::with(['product:id,title,showable_name', 'landing', 'testLanding'])->where('status', 'tested')->where('tested_at', '>=', now()->subDays(30))
                ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))->orderByDesc('tested_at')->limit(10)->get());
        $data['encargos_de_landing'] = $tasks->map(fn (LandingTask $t) => array_filter([
            'numero' => $t->code() ?? 'sin número (sin aprobar)',
            'producto' => $t->product?->showable_name ?: $t->product?->title,
            'landing' => ($t->testLanding ?? $t->landing)?->label() ?? 'nueva',
            'estado' => LandingTaskRules::STATUSES[$t->status] ?? $t->status,
            'propuesto_por' => $t->source === 'rules' ? 'las reglas' : null,
            'tipo' => LandingTaskRules::KINDS[$t->kind] ?? $t->kind,
            'angulo' => $t->angle_code,
            'que_cambiar' => $t->change,
            'test' => $t->result['text'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''))->values()->all() ?: 'No hay encargos de landing abiertos.';
        if ($productId === null && $tasks->isEmpty()) {
            return new ToolResult(['problema' => 'No hay encargos de landing. Para ver las landings de un producto, di cuál.'],
                [['type' => 'link', 'label' => 'Abrir Landings', 'to' => '/meta-ads/landings']]);
        }

        $display = $items ? [['type' => 'list', 'title' => 'Landings (7 días)', 'items' => $items]] : [];
        $display[] = ['type' => 'link', 'label' => 'Abrir Landings', 'to' => '/meta-ads/landings'];

        return new ToolResult($data, $display);
    }

    /** "1.200 clics, 960 visitas (80 % de visita), 30 compras (3,1 % de compra), CPA $9,80 (82 % de C)". */
    private static function line(array $b): string
    {
        $t = $b['totals'];
        $m = $b['metrics'];
        $n = fn ($v) => number_format($v, 0, ',', '.');
        $p = fn ($v) => $v === null ? 'N/A' : str_replace('.', ',', (string) $v) . ' %';

        return "{$n($t['link_clicks'])} clics, {$n($t['visits'])} visitas ({$p($m['visit'])} de visita), {$n($t['purchases'])} compras ({$p($m['conversion'])} de compra)"
            . ($m['cpa'] !== null ? ', CPA ' . self::money($m['cpa']) : '') . ($m['cpa_vs_c'] !== null ? " ({$p($m['cpa_vs_c'])} de C)" : '');
    }
}
