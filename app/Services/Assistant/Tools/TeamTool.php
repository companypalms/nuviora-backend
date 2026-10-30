<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\Lookup;
use App\Services\Assistant\Present;

/** Equipo del Panel del dueño: el ranking de un rol (vendedoras, líderes, agencias, repartidores, operadoras o editores de video). */
class TeamTool extends PanelTool
{
    /** Las personas que lee la IA (el ranking entero se ve en la tabla de la pantalla). */
    private const MAX_PEOPLE = 30;

    private const MAX_TABLE = 60;

    private const MAX_CHART = 12;

    public function name(): string
    {
        return 'equipo';
    }

    public function description(): string
    {
        return 'El ranking de un equipo en un período, con los números de cada persona (efectividad, pedidos, tiempos, etc., según el rol) y los del equipo entero. Úsala para "ranking", "quién vende más", "mejores agencias", "cómo van las vendedoras", "cómo van los editores". Para una sola persona, usa "persona". Quien tiene menos del mínimo de pedidos tiene "pocos datos" y no se compara.';
    }

    public function parameters(): array
    {
        return self::object(['rol' => self::roleSchema()] + PeriodArgs::schema() + self::filtersSchema() + self::chartSchema('el ranking'));
    }

    public static function roleSchema(): array
    {
        return ['type' => 'string', 'enum' => array_keys(Lookup::ROLES), 'description' => 'El equipo. "lideres" son los grupos de venta con su líder.'];
    }

    public function working(array $args): string
    {
        return 'Revisando ' . match ($args['rol'] ?? '') {
            'agencias' => 'las agencias',
            'repartidores' => 'los repartidores',
            'lideres' => 'los grupos de venta',
            'operadoras' => 'las operadoras',
            'editores' => 'los editores de video',
            default => 'las vendedoras',
        } . '…';
    }

    public function run(array $args): ToolResult
    {
        [$period, $filters] = $this->scope($args);
        $d = $this->panel->team($period, $filters, Lookup::ROLES[$args['rol'] ?? ''] ?? 'sellers');
        $role = $d['role'];
        $columns = $role['columns'];
        $labels = array_column($columns, 'label', 'key');
        $words = fn (array $values) => array_combine(
            array_map(fn ($c) => $c['label'], $columns),
            array_map(fn ($c) => Present::value($values[$c['key']] ?? null, $c['format']), $columns),
        );

        $data = self::base($period, $args, $d) + array_filter([
            'equipo' => $role['label'],
            'nota_filtros' => $role['filters_note'],
            'ordenado_por' => $labels[$role['rank_by']] ?? $role['rank_by'],
            'pocos_datos_si_tiene_menos_de' => "{$role['base']['min']} {$role['base']['label']}",
            'tarjetas' => $d['cards'] !== [] ? Present::cards($d['cards']) : null,
            'todo_el_equipo' => $words($role['totals']),
            'personas' => array_map(fn ($r) => array_filter([
                'nombre' => $r['name'],
                'grupo' => $r['group'],
                'pocos_datos' => $r['low_data'] ?: null,
            ]) + $words($r['values']), array_slice($role['rows'], 0, self::MAX_PEOPLE)),
            'personas_que_no_se_muestran' => count($role['rows']) > self::MAX_PEOPLE ? count($role['rows']) - self::MAX_PEOPLE : null,
        ], fn ($v) => $v !== null);

        $display = [];
        if ($d['cards'] !== []) {
            $display[] = Present::cardsBlock(self::title($role['label'], $period), $d['period']['compare_label'], $d['cards']);
        }
        if (!empty($args['grafico'])) {
            $rank = $role['rank_by'];
            $rankFormat = array_column($columns, 'format', 'key')[$rank] ?? 'count';
            $ranked = array_slice(array_values(array_filter($role['rows'], fn ($r) => !$r['low_data'] && ($r['values'][$rank] ?? null) !== null)), 0, self::MAX_CHART);
            if ($ranked !== []) {
                $display[] = Present::chart('hbar', "{$role['label']}: " . mb_strtolower($labels[$rank] ?? $rank), $rankFormat, [['key' => 'value', 'label' => $labels[$rank] ?? $rank]],
                    array_map(fn ($r) => ['label' => $r['name'], 'value' => $r['values'][$rank]], $ranked));
            }
        }
        $display[] = [
            'type' => 'table',
            'title' => self::title($role['label'], $period),
            'columns' => array_map(fn ($c) => ['key' => $c['key'], 'label' => $c['label'], 'format' => $c['format']], $columns),
            'rows' => array_map(fn ($r) => ['name' => $r['name'], 'group' => $r['group'], 'values' => $r['values'], 'low_data' => $r['low_data']],
                array_slice($role['rows'], 0, self::MAX_TABLE)),
        ];

        return new ToolResult($data, $display);
    }
}
