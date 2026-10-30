<?php

namespace App\Services\Metrics\Team;

use App\Services\Meta\Landings\LandingEditorFacts;
use App\Services\Meta\Landings\LandingTaskRules;
use App\Services\Meta\Landings\LandingTestRules;
use App\Services\Metrics\Filters;
use App\Services\Metrics\Period;
use App\Services\Metrics\Readers\TeamReader;

/**
 * Editores de landings (Etapa 5, paso 6c; los KPIs que eligió el dueño el 30/10/2026). PHP puro. Sale de los encargos de
 * landing: sus entregas y el test de cada landing publicada (los mismos anuncios antes y después, LandingTestRules).
 *
 * Las reglas:
 * - Cada encargo es de quien lo tiene asignado. Por ahora las landings las hace el dueño: él también sale ("Dueño").
 * - A tiempo: la primera entrega (la V01) hasta el final del día del plazo.
 * - Tests: los que terminaron en el período con veredicto (ganó, perdió o empate); los "sin muestra" no cuentan.
 * - Compra después contra antes: todas las compras y visitas de esos tests sumadas (no un promedio de porcentajes): cuánto
 *   subió o bajó el % de compra con los mismos anuncios.
 * - % de visita: de los clics de después, cuántos llegaron a la página.
 * - Nada de dinero: los editores ven lo mismo en "Mis resultados".
 */
final class LandingEditorKpis extends TeamRole
{
    public const METRICS = [
        'delivered' => [
            'label' => 'Encargos entregados',
            'format' => 'count',
            'better' => 'higher',
            'card' => true,
            'help' => 'Encargos de landing que entregó por primera vez en este período. Las correcciones (V02 o más) no cuentan otra vez.',
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
        'won' => [
            'label' => 'Tests ganados',
            'format' => 'percent',
            'better' => 'higher',
            'matures' => true,
            'card' => true,
            'help' => 'De sus landings cuyo test terminó en este período con muestra suficiente, cuántas ganaron (el % de compra subió 15 % o más con los mismos anuncios).',
        ],
        'conversion_change' => [
            'label' => 'Compra: después contra antes',
            'format' => 'percent',
            'better' => 'higher',
            'matures' => true,
            'card' => true,
            'help' => 'Cuánto subió (o bajó) el % de compra de sus landings con los mismos anuncios: todas las compras y visitas de esos tests sumadas, después contra antes.',
        ],
        'visit' => [
            'label' => '% de visita',
            'format' => 'percent',
            'better' => 'higher',
            'matures' => true,
            'card' => true,
            'help' => 'De los clics de después de publicar, cuántos llegaron a la página. Debajo de 55 % la landing es lenta.',
        ],
    ];

    public function key(): string
    {
        return 'landing_editors';
    }

    public function label(): string
    {
        return 'Editores de landings';
    }

    public function systemRoles(): array
    {
        return [LandingTaskRules::ROLE];
    }

    public function help(): string
    {
        return 'Cada editor de landings con lo que hizo en el período: cuántos encargos entregó y si fue a tiempo, cuánto le devolvieron, y cómo les fue a sus landings publicadas con los mismos anuncios (tests ganados, compra antes y después, % de visita). Por ahora las landings las haces tú: sales como "Dueño".';
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
        return 'won';
    }

    public function base(): array
    {
        return ['key' => 'delivered', 'min' => 2, 'label' => 'encargos entregados'];
    }

    public function facts(TeamReader $reader, Period $period, Filters $filters): mixed
    {
        $facts = (new LandingEditorFacts())->read($period);

        return $facts + ['people' => $reader->people($this->systemRoles()) + $facts['owners']];
    }

    /** facts: people; tasks y reviews de LandingEditorFacts::read. */
    public function compute(mixed $facts, Period $period): array
    {
        $people = $facts['people'];
        $raw = [];
        $total = self::emptyRaw();
        $bump = function (int $id, string $key, float $n = 1) use (&$raw, &$total, $people) {
            if (!isset($people[$id])) {
                return;
            }
            $raw[$id] ??= self::emptyRaw();
            $raw[$id][$key] += $n;
            $total[$key] += $n;
        };

        foreach ($facts['tasks'] as $t) {
            if (self::inPeriod($t['delivered_at'], $period)) {
                $bump($t['editor_id'], 'delivered');
                $bump($t['editor_id'], 'on_time', LandingTaskRules::onTime($t['delivered_at'], $t['due_date']) ? 1 : 0);
            }
            $r = $t['result'];
            if ($t['status'] === 'tested' && self::inPeriod($t['tested_at'], $period) && in_array($r['state'] ?? null, ['won', 'lost', 'tie'], true)) {
                $bump($t['editor_id'], 'tests');
                $bump($t['editor_id'], 'won', $r['state'] === 'won' ? 1 : 0);
                foreach (['before', 'after'] as $side) {
                    $bump($t['editor_id'], "{$side}_visits", (float) ($r[$side]['totals']['visits'] ?? 0));
                    $bump($t['editor_id'], "{$side}_purchases", (float) ($r[$side]['totals']['purchases'] ?? 0));
                }
                $bump($t['editor_id'], 'after_clicks', (float) ($r['after']['totals']['link_clicks'] ?? 0));
            }
        }
        foreach ($facts['reviews'] as $r) {
            $bump($r['editor_id'], 'reviewed');
            $bump($r['editor_id'], 'returned', $r['review'] === 'returned' ? 1 : 0);
        }

        $out = [];
        foreach ($raw as $id => $r) {
            $out[$id] = ['name' => $people[$id]['name'] ?? "Editor {$id}", 'group' => $people[$id]['group'] ?? null] + self::values($r);
        }

        return ['people' => $out, 'totals' => self::values($total)];
    }

    /**
     * Sus landings publicadas para "Mis resultados" (sin dinero): en test arriba, después lo testeado más nuevo, con el
     * veredicto y la compra antes y después.
     *
     * @param list<int>|null $editorIds null = todos
     */
    public static function tasks(array $facts, ?array $editorIds = null, int $limit = 60): array
    {
        $list = array_filter($facts['tasks'], fn ($t) => in_array($t['status'], ['testing', 'tested'], true)
            && ($editorIds === null || in_array($t['editor_id'], $editorIds, true)));
        usort($list, fn ($a, $b) => [$a['status'] === 'tested', $b['tested_at'] ?? $b['published_at'] ?? ''] <=> [$b['status'] === 'tested', $a['tested_at'] ?? $a['published_at'] ?? '']);

        return array_map(fn ($t) => [
            'id' => $t['id'], 'code' => $t['code'], 'product' => $t['product'], 'landing' => $t['landing'], 'editor_id' => $t['editor_id'],
            'status' => $t['status'], 'status_label' => LandingTaskRules::STATUSES[$t['status']] ?? $t['status'],
            'published_at' => $t['published_at'], 'tested_at' => $t['tested_at'],
            'state' => $t['result']['state'] ?? 'waiting', 'state_label' => LandingTestRules::STATES[$t['result']['state'] ?? 'waiting'] ?? null,
            'change' => $t['result']['change'] ?? null,
            'before' => isset($t['result']['before']) ? ['conversion' => $t['result']['before']['metrics']['conversion'], 'visit' => $t['result']['before']['metrics']['visit']] : null,
            'after' => isset($t['result']['after']) ? ['conversion' => $t['result']['after']['metrics']['conversion'], 'visit' => $t['result']['after']['metrics']['visit']] : null,
            'on_time' => LandingTaskRules::onTime($t['delivered_at'], $t['due_date']), 'versions' => $t['versions'], 'returned' => $t['returned'],
        ], array_slice($list, 0, $limit));
    }

    private static function emptyRaw(): array
    {
        return ['delivered' => 0, 'on_time' => 0, 'reviewed' => 0, 'returned' => 0, 'tests' => 0, 'won' => 0,
            'before_visits' => 0.0, 'before_purchases' => 0.0, 'after_visits' => 0.0, 'after_purchases' => 0.0, 'after_clicks' => 0.0];
    }

    private static function values(array $r): array
    {
        $before = $r['before_visits'] > 0 ? $r['before_purchases'] / $r['before_visits'] : null;
        $after = $r['after_visits'] > 0 ? $r['after_purchases'] / $r['after_visits'] : null;
        $change = $before && $after !== null ? round(($after / $before - 1) * 100, 1) : null;
        $num = fn (?float $v) => $v === null ? 'N/A' : rtrim(rtrim(number_format($v * 100, 2, ',', '.'), '0'), ',') . ' %';

        return [
            'values' => [
                'delivered' => (int) $r['delivered'],
                'on_time' => self::pct($r['on_time'], $r['delivered']),
                'returned' => self::pct($r['returned'], $r['reviewed']),
                'won' => self::pct($r['won'], $r['tests']),
                'conversion_change' => $change,
                'visit' => self::pct($r['after_visits'], $r['after_clicks']),
            ],
            'details' => [
                'on_time' => "{$r['on_time']} de " . self::plural((int) $r['delivered'], 'encargo', 'encargos'),
                'returned' => "{$r['returned']} de " . self::plural((int) $r['reviewed'], 'entrega revisada', 'entregas revisadas'),
                'won' => "{$r['won']} de " . self::plural((int) $r['tests'], 'test con muestra', 'tests con muestra'),
                'conversion_change' => $before !== null ? 'Compra ' . $num($before) . ' antes → ' . $num($after) . ' después' : 'Todavía sin tests terminados',
                'visit' => $r['after_clicks'] > 0 ? 'De ' . number_format($r['after_clicks'], 0, ',', '.') . ' clics después de publicar' : 'Todavía sin tests terminados',
            ],
        ];
    }
}
