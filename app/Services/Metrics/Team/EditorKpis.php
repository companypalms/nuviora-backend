<?php

namespace App\Services\Metrics\Team;

use App\Services\Meta\Queue\EditorFacts;
use App\Services\Meta\Queue\EditorRules;
use App\Services\Meta\Queue\QueueRules;
use App\Services\Metrics\Filters;
use App\Services\Metrics\Period;
use App\Services\Metrics\Readers\TeamReader;

/**
 * Editores de video (Etapa 5, paso 5b; los KPIs que eligió el dueño el 30/10/2026). PHP puro. Sale de la cola de
 * producción: sus encargos, sus entregas y lo que hicieron en Meta los anuncios de cada encargo (EditorFacts).
 *
 * Las reglas:
 * - Cada encargo es del editor que lo tiene asignado. Los creativos viejos (sin editor) no cuentan.
 * - A tiempo: la primera entrega (la V01) hasta el final del día del plazo; las correcciones no cuentan otra vez.
 * - Ganador: el batch terminó el test con "prometedor" o "PROVEN" en al menos una ciudad.
 * - CPA contra C: el gasto contra lo que se podía gastar a C por las compras que trajo (compras × C de cada ciudad). Así
 *   un batch de una ciudad cara no se mezcla mal con uno de una barata. Sin C en esa ciudad, no se compara.
 * - Ganancia estimada: compras × BE − gasto. El BE de cada ciudad ya trae la tasa real de entrega.
 * - El editor líder se mide igual, y el total del rol es el del equipo entero.
 * - Los editores no ven dinero: WITHOUT_MONEY dice qué se les esconde (en "Mis resultados").
 */
final class EditorKpis extends TeamRole
{
    public const METRICS = [
        'delivered' => [
            'label' => 'Encargos entregados',
            'format' => 'count',
            'better' => 'higher',
            'card' => true,
            'help' => 'Encargos que entregó por primera vez en este período. Las correcciones (V02 o más) no cuentan otra vez.',
        ],
        'on_time' => [
            'label' => 'Entregas a tiempo',
            'format' => 'percent',
            'better' => 'higher',
            'card' => true,
            'help' => 'De esos encargos, cuántos entregó por primera vez dentro del plazo (hasta el final del día del plazo).',
        ],
        'returned' => [
            'label' => 'Devuelto por calidad',
            'format' => 'percent',
            'better' => 'lower',
            'help' => 'De sus entregas que se revisaron en este período, cuántas le devolvieron para corregir.',
        ],
        'winners' => [
            'label' => '% de ganadores',
            'format' => 'percent',
            'better' => 'higher',
            'matures' => true,
            'card' => true,
            'help' => 'De sus batches que terminaron el test en este período (con muestra suficiente), cuántos salieron prometedores o ganadores (PROVEN) en al menos una ciudad.',
        ],
        'cpa_vs_c' => [
            'label' => 'CPA contra C',
            'format' => 'percent',
            'better' => 'lower',
            'matures' => true,
            'card' => true,
            'help' => 'Lo que gastaron sus creativos en el período contra lo que se podía gastar a C (el CPA objetivo de cada ciudad) por las compras que trajeron. 100 % es justo el objetivo; menos es mejor.',
        ],
        'purchases' => [
            'label' => 'Compras',
            'format' => 'count',
            'better' => 'higher',
            'matures' => true,
            'help' => 'Compras de Meta que trajeron sus creativos en el período.',
        ],
        'spend' => [
            'label' => 'Gasto en sus creativos',
            'format' => 'money',
            'better' => 'none',
            'help' => 'Lo que se gastó en Meta en sus creativos en el período.',
        ],
        'profit' => [
            'label' => 'Ganancia estimada',
            'format' => 'money',
            'better' => 'higher',
            'matures' => true,
            'card' => true,
            'help' => 'Compras × BE − gasto: lo que dejaron sus creativos. El BE de cada ciudad ya trae la tasa real de entrega, así que no se descuentan las devoluciones otra vez.',
        ],
    ];

    /** Lo que los editores no ven de sí mismos (decisión del dueño: sin dólares). */
    public const WITHOUT_MONEY = ['spend', 'profit'];

    private const WINNER = ['promising', 'proven'];

    public function key(): string
    {
        return 'editors';
    }

    public function label(): string
    {
        return 'Editores de video';
    }

    public function systemRoles(): array
    {
        return EditorRules::ROLES;
    }

    public function help(): string
    {
        return 'Cada editor de video con lo que hizo en el período: cuántos encargos entregó y si fue a tiempo, cuánto le devolvieron, y cómo les fue en Meta a sus creativos (ganadores, CPA contra C, compras y la ganancia que dejaron). Solo cuentan los encargos de la cola de producción, no los creativos viejos.';
    }

    public function filtersNote(): ?string
    {
        return 'Los filtros de tienda, ciudad y vendedora no se aplican aquí: cada editor se mide con todos sus encargos.';
    }

    public function metrics(): array
    {
        return self::METRICS;
    }

    public function rankBy(): string
    {
        return 'profit';
    }

    public function base(): array
    {
        return ['key' => 'delivered', 'min' => 3, 'label' => 'encargos entregados'];
    }

    public function facts(TeamReader $reader, Period $period, Filters $filters): mixed
    {
        return (new EditorFacts())->read($period) + ['people' => $reader->people($this->systemRoles())];
    }

    /** facts: people; batches, reviews y rows de EditorFacts::read. */
    public function compute(mixed $facts, Period $period): array
    {
        $people = $facts['people'];
        $raw = [];
        $total = self::emptyRaw();
        $editorOf = [];
        $bump = function (int $id, string $key, float $n = 1) use (&$raw, &$total, $people) {
            if (!isset($people[$id])) {
                return;
            }
            $raw[$id] ??= self::emptyRaw();
            $raw[$id][$key] += $n;
            $total[$key] += $n;
        };

        foreach ($facts['batches'] as $b) {
            $editorOf[$b['id']] = $b['editor_id'];
            if (self::inPeriod($b['delivered_at'], $period)) {
                $bump($b['editor_id'], 'delivered');
                $bump($b['editor_id'], 'on_time', EditorRules::onTime($b['delivered_at'], $b['due_date']) ? 1 : 0);
            }
            if ($b['status'] === 'tested' && self::inPeriod($b['tested_at'], $period) && $b['verdicts'] !== []) {
                $bump($b['editor_id'], 'tested');
                $bump($b['editor_id'], 'winners', self::isWinner($b['verdicts']) ? 1 : 0);
            }
        }
        foreach ($facts['reviews'] as $r) {
            $bump($r['editor_id'], 'reviewed');
            $bump($r['editor_id'], 'returned', $r['review'] === 'returned' ? 1 : 0);
        }
        foreach ($facts['rows'] as $row) {
            $id = $editorOf[$row['batch_id']] ?? null;
            if ($id === null || ($row['spend'] <= 0 && $row['purchases'] <= 0)) {
                continue;
            }
            $bump($id, 'spend', $row['spend']);
            $bump($id, 'purchases', $row['purchases']);
            if ($row['c'] > 0) {
                $bump($id, 'spend_with_c', $row['spend']);
                $bump($id, 'allowed', $row['purchases'] * $row['c']);
            }
            if ($row['be'] > 0) {
                $bump($id, 'profit', $row['purchases'] * $row['be'] - $row['spend']);
                $bump($id, 'profit_rows', 1);
            }
        }

        $out = [];
        foreach ($raw as $id => $r) {
            $out[$id] = ['name' => $people[$id]['name'] ?? "Editor {$id}", 'group' => $people[$id]['group'] ?? null] + self::values($r);
        }

        return ['people' => $out, 'totals' => self::values($total)];
    }

    /**
     * Los batches de un editor (o del equipo) para "Mis resultados", sin dinero: el estado, el veredicto de cada ciudad, las
     * compras y el CPA de toda su vida contra C, si fue a tiempo y cuántas veces se devolvió. Lo testeado más nuevo arriba.
     *
     * @param list<int>|null $editorIds null = todos
     */
    public static function batches(array $facts, ?array $editorIds = null, int $limit = 60): array
    {
        $life = [];
        foreach ($facts['rows'] as $row) {
            $l = &$life[$row['batch_id']];
            $l ??= ['purchases' => 0.0, 'spend_with_c' => 0.0, 'allowed' => 0.0];
            $l['purchases'] += $row['life_purchases'];
            if ($row['c'] > 0) {
                $l['spend_with_c'] += $row['life_spend'];
                $l['allowed'] += $row['life_purchases'] * $row['c'];
            }
            unset($l);
        }
        $list = array_filter($facts['batches'], fn ($b) => in_array($b['status'], ['bank', 'testing', 'tested'], true)
            && ($editorIds === null || in_array($b['editor_id'], $editorIds, true)));
        usort($list, fn ($a, $b) => [$a['status'] === 'tested', $b['tested_at'] ?? $b['testing_at'] ?? ''] <=> [$b['status'] === 'tested', $a['tested_at'] ?? $a['testing_at'] ?? '']);

        return array_map(function ($b) use ($life) {
            $l = $life[$b['id']] ?? null;

            return [
                'id' => $b['id'], 'code' => $b['code'], 'product' => $b['product'], 'editor_id' => $b['editor_id'],
                'status' => $b['status'], 'status_label' => QueueRules::STATUSES[$b['status']] ?? $b['status'],
                'testing_at' => $b['testing_at'], 'tested_at' => $b['tested_at'], 'verdicts' => $b['verdicts'],
                'winner' => $b['status'] === 'tested' ? self::isWinner($b['verdicts']) : null,
                'purchases' => $l ? (int) round($l['purchases']) : 0,
                'cpa_vs_c' => $l ? self::pct($l['spend_with_c'], $l['allowed']) : null,
                'on_time' => EditorRules::onTime($b['delivered_at'], $b['due_date']),
                'versions' => $b['versions'], 'returned' => $b['returned'],
            ];
        }, array_slice($list, 0, $limit));
    }

    /** Lo mismo que ve el dueño, sin los dólares: para "Mis resultados" y "Resultados del equipo". */
    public static function withoutMoney(array $metrics): array
    {
        return array_diff_key($metrics, array_flip(self::WITHOUT_MONEY));
    }

    private static function isWinner(array $verdicts): bool
    {
        foreach ($verdicts as $v) {
            if (in_array($v['key'] ?? null, self::WINNER, true)) {
                return true;
            }
        }

        return false;
    }

    private static function emptyRaw(): array
    {
        return ['delivered' => 0, 'on_time' => 0, 'reviewed' => 0, 'returned' => 0, 'tested' => 0, 'winners' => 0, 'spend' => 0.0, 'purchases' => 0.0,
            'spend_with_c' => 0.0, 'allowed' => 0.0, 'profit' => 0.0, 'profit_rows' => 0];
    }

    private static function values(array $r): array
    {
        return [
            'values' => [
                'delivered' => (int) $r['delivered'],
                'on_time' => self::pct($r['on_time'], $r['delivered']),
                'returned' => self::pct($r['returned'], $r['reviewed']),
                'winners' => self::pct($r['winners'], $r['tested']),
                'cpa_vs_c' => self::pct($r['spend_with_c'], $r['allowed']),
                'purchases' => (int) round($r['purchases']),
                'spend' => round($r['spend'], 2),
                'profit' => $r['profit_rows'] > 0 ? round($r['profit'], 2) : null,
            ],
            // Sin montos: estos textos también los ven los editores
            'details' => [
                'on_time' => "{$r['on_time']} de " . self::plural((int) $r['delivered'], 'encargo', 'encargos'),
                'returned' => "{$r['returned']} de " . self::plural((int) $r['reviewed'], 'entrega revisada', 'entregas revisadas'),
                'winners' => "{$r['winners']} de " . self::plural((int) $r['tested'], 'batch testeado', 'batches testeados'),
                'cpa_vs_c' => $r['allowed'] > 0 ? 'Con ' . self::plural((int) round($r['purchases']), 'compra', 'compras')
                    : ($r['spend'] > 0 ? 'Todavía sin compras' : 'Todavía sin gasto en Meta'),
            ],
        ];
    }
}
