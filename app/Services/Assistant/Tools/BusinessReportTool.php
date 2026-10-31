<?php

namespace App\Services\Assistant\Tools;

use App\Models\BusinessReport;
use App\Services\Metrics\Reports\ReportFacts;
use App\Services\Metrics\Reports\ReportPeriods;

/**
 * El reporte del negocio ya hecho (`.48b-2`), como en Reportes del negocio: el resumen de la IA, qué mirar primero, los
 * números principales (los del Panel, revisados) y si los números se pueden creer. Solo lee: no lo genera de nuevo ni lo
 * marca como visto (eso pasa al abrir la página).
 */
class BusinessReportTool implements Tool
{
    private const STATUS = [
        'example' => 'Es un ejemplo: en esta PC no hay IA, el texto lo armó el sistema con los mismos números.',
        'no_ai' => 'La IA no está configurada en el servidor: el texto lo armó el sistema con los mismos números.',
        'error' => 'La IA no pudo escribir este reporte: el texto lo armó el sistema con los mismos números.',
    ];

    public function name(): string
    {
        return 'reporte_negocio';
    }

    public function description(): string
    {
        return 'El reporte del negocio que ya está hecho: diario (los últimos 3 días, sale de lunes a sábado a las 7:15), semanal (la semana pasada de lunes a domingo, sale el jueves) o mensual (el mes pasado, sale el día 8). Trae el resumen, qué mirar primero, los números principales de ventas, dinero y pérdidas con su comparación, los productos que más dejaron y los que perdieron, el equipo, las metas, las alertas y si los números están comprobados. Úsala para "dame el reporte", "cómo fue la semana", "cómo va el negocio", "el reporte del mes". Para unas fechas que no son las de un reporte, usa ventas o dinero.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tipo' => ['type' => ['string', 'null'], 'enum' => ['diario', 'semanal', 'mensual', null],
                    'description' => 'Qué reporte. null = el semanal.'],
            ],
            'required' => ['tipo'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Buscando tu reporte del negocio…';
    }

    public function run(array $args): ToolResult
    {
        $type = ['diario' => 'daily', 'semanal' => 'weekly', 'mensual' => 'monthly'][$args['tipo'] ?? 'semanal'] ?? 'weekly';
        $label = mb_strtolower(ReportPeriods::LABELS[$type]);
        $r = BusinessReport::with('requester:id,names')->where('type', $type)->orderByDesc('period_to')->orderByDesc('version')->first();
        $link = ['type' => 'link', 'label' => 'Abrir el reporte', 'to' => '/reportes-negocio?tipo=' . $type
            . ($r ? '&desde=' . $r->period_from->toDateString() . '&hasta=' . $r->period_to->toDateString() : '')];
        if (!$r) {
            return new ToolResult(['problema' => "Todavía no hay reporte {$label}. Sale solo a las 7:15 en su día, o en Reportes del negocio con \"Volver a generar\"."], [$link]);
        }

        $f = $r->facts;
        $text = $r->text ?? [];
        $numbers = [];
        foreach (['sales' => 'ventas', 'money' => 'dinero', 'losses' => 'perdidas'] as $section => $key) {
            $numbers[$key] = array_map(fn ($c) => rtrim(ReportFacts::cardLine($c), '.'), $f['cards'][$section] ?? []);
        }
        $data = array_filter([
            'reporte' => ReportPeriods::LABELS[$type],
            'fechas' => $f['period']['label'],
            'comparado_con' => $f['compare_label'],
            'version' => $r->version,
            'hecho' => $r->requested_by ? 'a pedido de ' . ($r->requester->names ?? 'alguien') . ' el ' . $r->created_at->format('d/m H:i')
                : 'automático, el ' . $r->created_at->format('d/m H:i'),
            'nota' => self::STATUS[$r->status] ?? null,
            'todavia_se_entregan' => $f['maturing'] ? 'Los pedidos de los últimos días todavía se están entregando: esos números pueden subir.' : null,
            'numeros_comprobados' => $f['check']['ok'] ? 'Sí. ' . $f['check']['text'] : 'No. ' . $f['check']['text'],
            'datos_que_fallan' => array_map(fn ($p) => "{$p['text']} ({$p['count']})", $f['check']['problems']) ?: null,
            'resumen' => $text['resumen'] ?? null,
            'que_mirar_primero' => $text['que_mirar'] ?? null,
            'numeros' => $numbers,
            'productos_que_mas_dejaron' => array_map(fn ($p) => $p['name'], $f['products']['best']) ?: null,
            'productos_que_perdieron' => array_map(fn ($p) => $p['name'], $f['products']['losing']) ?: null,
            'alertas' => array_map(fn ($a) => $a['title'] . ': ' . implode(' ', $a['items']), $f['alerts']) ?: null,
            'numeros_que_la_ia_escribio_y_no_estan_en_los_datos' => $r->warnings ?: null,
        ], fn ($v) => $v !== null);

        $items = array_map(fn ($t) => ['text' => $t, 'level' => 'yellow'], $text['que_mirar'] ?? []);
        $display = $items ? [['type' => 'list', 'title' => 'Qué mirar primero · ' . $f['period']['label'], 'items' => $items]] : [];

        return new ToolResult($data, [...$display, $link]);
    }
}
