<?php

namespace App\Services\Metrics\Team;

use App\Services\Metrics\Filters;
use App\Services\Metrics\Period;
use App\Services\Metrics\Readers\TeamReader;

/**
 * Líderes de grupo de venta (diccionario de métricas, §4): los mismos números de las vendedoras, por grupo, y la
 * comisión de la líder. No es un rol del sistema: la líder es una vendedora que lidera un grupo. PHP puro; el cálculo
 * es el de SellerKpis (en el grupo, un pedido que pasó por dos de sus vendedoras cuenta una vez).
 *
 * Las vendedoras de un grupo son las que estuvieron en él en algún momento del período, con todos sus pedidos.
 */
final class LeaderKpis extends TeamRole
{
    public const METRICS = [
        'sellers' => [
            'label' => 'Vendedoras',
            'format' => 'count',
            'better' => 'none',
            'help' => 'Vendedoras del grupo (la líder incluida) que atendieron pedidos en este período.',
        ],
        'attended' => SellerKpis::METRICS['attended'],
        'effectiveness' => SellerKpis::METRICS['effectiveness'],
        'confirmation' => SellerKpis::METRICS['confirmation'],
        'cancel_rate' => SellerKpis::METRICS['cancel_rate'],
        'response_time' => SellerKpis::METRICS['response_time'],
        'revenue' => SellerKpis::METRICS['revenue'],
        'commission' => [
            'label' => 'Comisión de las vendedoras',
            'format' => 'money',
            'better' => 'higher',
            'matures' => true,
            'help' => 'Lo que ganaron las vendedoras del grupo (venta y upsells) con los pedidos que entregaron, de los que atendieron en este período.',
        ],
        'leader_commission' => [
            'label' => 'Comisión de la líder',
            'format' => 'money',
            'better' => 'higher',
            'matures' => true,
            'card' => true,
            'help' => 'Lo que ganó la líder por las entregas de su grupo, de los pedidos que atendieron en este período.',
        ],
    ];

    public function __construct(private SellerKpis $sellers = new SellerKpis())
    {
    }

    public function key(): string
    {
        return 'leaders';
    }

    public function label(): string
    {
        return 'Líderes';
    }

    public function systemRoles(): array
    {
        return [];
    }

    public function help(): string
    {
        return 'Cada grupo de venta con el total de sus vendedoras y la comisión de su líder. Al hacer clic en un grupo se ve cada una de sus vendedoras.';
    }

    public function metrics(): array
    {
        $metrics = self::METRICS;
        // En las líderes, las tarjetas de arriba son las del total de los grupos
        foreach (['attended', 'effectiveness', 'revenue'] as $key) {
            $metrics[$key]['card'] = true;
        }
        $metrics['response_time']['card'] = false;

        return $metrics;
    }

    public function rankBy(): string
    {
        return 'effectiveness';
    }

    public function base(): array
    {
        return ['key' => 'attended', 'min' => 10, 'label' => 'pedidos atendidos'];
    }

    public function facts(TeamReader $reader, Period $period, Filters $filters): mixed
    {
        return $this->sellers->facts($reader, $period, $filters);
    }

    public function compute(mixed $facts, Period $period): array
    {
        $computed = $this->sellers->compute($facts, $period);
        $people = [];
        foreach ($facts['groups'] as $gid => $g) {
            if (!isset($computed['groups'][$gid])) {
                continue;
            }
            $group = $computed['groups'][$gid];
            $people[$gid] = [
                'name' => $g['name'],
                'group' => $g['leader'] ? "Líder: {$g['leader']}" : 'Sin líder',
                'values' => ['sellers' => $group['sellers']] + $group['values'],
                'details' => $group['details'],
            ];
        }
        $all = $computed['groups_total'];

        return [
            'people' => $people,
            // El total: los pedidos de las vendedoras que están en algún grupo (un pedido cuenta una vez)
            'totals' => ['values' => ['sellers' => $all['sellers']] + $all['values'], 'details' => $all['details']],
            'sellers' => $computed,
            'members' => array_map(fn ($g) => array_values(array_map('intval', $g['members'])), $facts['groups']),
        ];
    }

    public function blocks(int $id, array $computed, array $before = []): array
    {
        $members = $computed['members'][$id] ?? [];
        $rows = array_intersect_key($computed['sellers']['people'], array_flip($members));

        return [[
            'type' => 'table',
            'title' => 'Sus vendedoras',
            'help' => 'Cada vendedora del grupo con sus números del período, calculados igual que en Vendedoras.',
            'role' => 'sellers',
            'people' => $rows,
            'previous' => $before['sellers']['people'] ?? [],
        ]];
    }
}
