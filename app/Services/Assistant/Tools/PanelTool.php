<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\Lookup;
use App\Services\Assistant\Present;
use App\Services\Metrics\Filters;
use App\Services\Metrics\OwnerPanel;
use App\Services\Metrics\Period;
use Carbon\CarbonImmutable;

/**
 * Lo común de las herramientas que leen el Panel del dueño: el período, los filtros (tienda, ciudad y producto, por
 * nombre) y si mostrar una gráfica. Los números salen de OwnerPanel, con las reglas del diccionario de métricas.
 */
abstract class PanelTool implements Tool
{
    public function __construct(protected OwnerPanel $panel, protected Lookup $lookup)
    {
    }

    /** Un objeto de JSON Schema estricto: todos los parámetros requeridos, sin otros. */
    protected static function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    protected static function filtersSchema(): array
    {
        return [
            'tienda' => ['type' => ['string', 'null'], 'description' => 'Solo los pedidos de esa tienda, por su nombre. null = todas.'],
            'ciudad' => ['type' => ['string', 'null'], 'description' => 'Solo los pedidos de esa ciudad, por su nombre. null = todas.'],
            'producto' => ['type' => ['string', 'null'], 'description' => 'Solo los pedidos de ese producto (el principal del pedido), por su nombre. null = todos.'],
        ];
    }

    protected static function chartSchema(string $what): array
    {
        return ['grafico' => ['type' => 'boolean', 'description' => "true para mostrarle al dueño una gráfica de {$what}. Úsala cuando ayude a ver la comparación o la evolución."]];
    }

    /** @return array{0: Period, 1: Filters} */
    protected function scope(array $args): array
    {
        $period = PeriodArgs::period($args, CarbonImmutable::now());
        $filters = new Filters(
            $this->lookup->shopId($args['tienda'] ?? null),
            $this->lookup->cityId($args['ciudad'] ?? null),
            $this->lookup->productId($args['producto'] ?? null),
        );

        return [$period, $filters];
    }

    /** Lo común de una sección del panel, para la IA: el período, los filtros y si los números todavía pueden subir. */
    protected static function base(Period $period, array $args, array $section): array
    {
        $filters = array_filter(['tienda' => $args['tienda'] ?? null, 'ciudad' => $args['ciudad'] ?? null, 'producto' => $args['producto'] ?? null]);

        return array_filter([
            'periodo' => Present::period($period),
            'filtros' => $filters !== [] ? $filters : null,
            'aviso' => !empty($section['maturing'])
                ? "Los pedidos de los últimos {$section['maturity_days']} días todavía se están entregando: estos números pueden subir."
                : null,
        ], fn ($v) => $v !== null);
    }

    /** "Ventas · Este mes", "Ventas · Del 19/10/2026 al 25/10/2026". */
    protected static function title(string $what, Period $period): string
    {
        return "{$what} · {$period->label}";
    }
}
