<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\Present;
use App\Services\Replenishment\PlanService;
use Illuminate\Support\Facades\DB;

/** El último plan de reabastecimiento (Inventario › Reabastecimiento): qué comprar, qué trasladar y cuánto dinero hace falta. */
class ReplenishmentTool implements Tool
{
    private const MAX = 25;

    public function __construct(private PlanService $plans)
    {
    }

    public function name(): string
    {
        return 'reabastecimiento';
    }

    public function description(): string
    {
        return 'El último plan de reabastecimiento que se generó: qué comprar (cantidad recomendada y proveedor), qué trasladar entre almacenes y por qué, y cuánto dinero hace falta. No genera un plan nuevo: eso se hace en Inventario › Reabastecimiento.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => [], 'additionalProperties' => false];
    }

    public function working(array $args): string
    {
        return 'Revisando el plan de reabastecimiento…';
    }

    public function run(array $args): ToolResult
    {
        $link = [['type' => 'link', 'label' => 'Abrir reabastecimiento', 'to' => '/reabastecimiento']];
        $last = $this->plans->history(1)[0] ?? null;
        if ($last === null) {
            return new ToolResult(['resumen' => 'Todavía no se generó ningún plan de reabastecimiento. Se genera en Inventario › Reabastecimiento.'], $link);
        }
        $plan = $this->plans->show((int) $last['id']);
        $purchases = array_values(array_filter((array) ($plan['purchases'] ?? []), fn ($p) => (int) ($p['recommended'] ?? 0) > 0));
        $transfers = array_values((array) ($plan['transfers'] ?? []));
        $products = DB::table('products')->whereIn('id', array_merge(array_column($purchases, 'product_id'), array_column($transfers, 'product_id')))
            ->get(['id', 'title', 'showable_name'])->mapWithKeys(fn ($p) => [(int) $p->id => $p->showable_name ?: $p->title]);
        $warehouses = DB::table('warehouses')->pluck('name', 'id');
        $capital = $plan['capital'] ?? [];

        return new ToolResult(array_filter([
            'generado' => $plan['generated_at'] . (!empty($plan['generated_by']) ? " por {$plan['generated_by']}" : ''),
            'aprobado' => $plan['approved_at'] ? "{$plan['approved_at']} por {$plan['approved_by']}" : 'todavía no',
            'dias_de_cobertura' => $plan['coverage_days'] ?? null,
            'comprar' => array_map(fn ($p) => array_filter([
                'producto' => $products[(int) $p['product_id']] ?? "Producto {$p['product_id']}",
                'recomendado' => (int) $p['recommended'],
                'ideal' => isset($p['ideal']) ? (int) $p['ideal'] : null,
                'proveedor' => is_array($p['supplier'] ?? null) ? ($p['supplier']['name'] ?? null) : null,
            ], fn ($v) => $v !== null), array_slice($purchases, 0, self::MAX)),
            'trasladar' => array_map(fn ($t) => [
                'producto' => $products[(int) $t['product_id']] ?? "Producto {$t['product_id']}",
                'cantidad' => (int) $t['quantity'],
                'desde' => $warehouses[$t['from']] ?? "Almacén {$t['from']}",
                'hacia' => $warehouses[$t['to']] ?? "Almacén {$t['to']}",
                'por_que' => $t['reason'] ?? null,
            ], array_slice($transfers, 0, self::MAX)),
            'dinero' => isset($capital['required_usd']) ? array_filter([
                'hace_falta' => Present::value((float) $capital['required_usd'], 'money'),
                'hay_en_caja' => isset($capital['cash_usd']) ? Present::value((float) $capital['cash_usd'], 'money') : null,
                'falta_conseguir' => isset($capital['additional_usd']) ? Present::value((float) $capital['additional_usd'], 'money') : null,
            ]) : null,
        ], fn ($v) => $v !== null && $v !== []), $link);
    }
}
