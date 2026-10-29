<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\Lookup;
use App\Services\Assistant\Present;
use App\Services\Metrics\Team\TeamCatalog;

/** Los números de una persona de Equipo (o de un grupo de venta): sus KPIs con la comparación y su detalle. */
class PersonTool extends PanelTool
{
    public function name(): string
    {
        return 'persona';
    }

    public function description(): string
    {
        return 'Todos los números de UNA persona del equipo en un período, comparados con el período anterior: por ejemplo la efectividad de una vendedora (entregados ÷ atendidos) o de una agencia (entregados ÷ recibidos), sus pedidos, tiempos y su detalle (embudo, motivos de cancelación, por ciudad). Se busca por nombre (o por id si ya lo tienes de buscar_persona). Si hay varias personas con ese nombre, devuelve la lista para preguntarle al dueño.';
    }

    public function parameters(): array
    {
        return self::object([
            'rol' => TeamTool::roleSchema(),
            'nombre' => ['type' => ['string', 'null'], 'description' => 'El nombre de la persona (o del grupo de venta), como lo dijo el dueño.'],
            'id' => ['type' => ['integer', 'null'], 'description' => 'El id, solo si ya lo tienes de buscar_persona.'],
        ] + PeriodArgs::schema() + self::filtersSchema());
    }

    public function working(array $args): string
    {
        return !empty($args['nombre']) ? 'Revisando los números de ' . mb_strimwidth((string) $args['nombre'], 0, 40, '…') . '…' : 'Revisando sus números…';
    }

    public function run(array $args): ToolResult
    {
        [$period, $filters] = $this->scope($args);
        $key = Lookup::ROLES[$args['rol'] ?? ''] ?? 'sellers';
        $role = TeamCatalog::find($key);
        $people = $this->lookup->people($key, $period);

        $id = isset($args['id']) && is_numeric($args['id']) ? (int) $args['id'] : null;
        if ($id === null || !isset($people[$id])) {
            $name = trim((string) ($args['nombre'] ?? ''));
            if ($name === '') {
                throw new ToolProblem('Falta el nombre de la persona.');
            }
            $found = $this->lookup->findPeople($key, $name, $period);
            if ($found === []) {
                throw new ToolProblem("No encontré a nadie que se llame \"{$name}\" en {$role->label()}. Puede ser de otro rol: prueba con buscar_persona.");
            }
            if (count($found) > 1) {
                return new ToolResult([
                    'varias_personas' => array_map(fn ($p) => array_filter(['id' => $p['id'], 'nombre' => $p['name'], 'grupo' => $p['group']]), $found),
                    'que_hacer' => 'Pregúntale al dueño a cuál se refiere.',
                ]);
            }
            $id = $found[0]['id'];
        }

        $p = $this->panel->teamPerson($period, $filters, $key, $id);
        if ($p === null) {
            return ToolResult::problem(($people[$id]['name'] ?? 'Esa persona') . " no tuvo actividad en este período ({$period->label}).");
        }

        $data = self::base($period, $args, []) + array_filter([
            'persona' => $p['name'],
            'grupo' => $p['group'],
            'rol' => $p['role'],
            'tarjetas' => Present::cards($p['cards']),
            'detalle' => array_map(fn ($b) => self::block($b), $p['blocks']),
        ], fn ($v) => $v !== null && $v !== []);

        $display = [Present::cardsBlock("{$p['name']} · {$period->label}", $p['compare_label'], $p['cards'])];
        foreach ($p['blocks'] as $b) {
            if ($b['type'] === 'bars' && array_sum(array_column($b['rows'], 'count')) > 0) {
                $display[] = Present::chart('hbar', $b['title'], 'count', [['key' => 'count', 'label' => 'Pedidos']],
                    array_map(fn ($r) => ['label' => $r['label'], 'count' => $r['count']], $b['rows']));
            } elseif ($b['type'] === 'table') {
                $display[] = [
                    'type' => 'table',
                    'title' => $b['title'],
                    'columns' => array_map(fn ($c) => ['key' => $c['key'], 'label' => $c['label'], 'format' => $c['format']], $b['columns']),
                    'rows' => array_map(fn ($r) => ['name' => $r['name'], 'group' => $r['group'], 'values' => $r['values'], 'low_data' => $r['low_data']], $b['rows']),
                ];
            }
        }

        return new ToolResult($data, $display);
    }

    /** Un bloque del detalle, para la IA: "Su embudo: Confirmado 12 de 20". */
    private static function block(array $b): array
    {
        if ($b['type'] === 'bars') {
            return ['titulo' => $b['title'], 'filas' => array_map(fn ($r) => "{$r['label']}: {$r['count']} de {$r['of']}", $b['rows'])];
        }
        $labels = array_column($b['columns'], 'label', 'key');
        $formats = array_column($b['columns'], 'format', 'key');

        return ['titulo' => $b['title'], 'personas' => array_map(function ($r) use ($labels, $formats) {
            $row = ['nombre' => $r['name']];
            foreach ($labels as $k => $label) {
                $row[$label] = Present::value($r['values'][$k] ?? null, $formats[$k]);
            }

            return $row;
        }, $b['rows'])];
    }
}
