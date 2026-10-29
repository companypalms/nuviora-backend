<?php

namespace App\Services\Metrics;

/** Los filtros del panel del dueño: tienda, ciudad y producto (el principal del pedido, como en Finanzas › Cohortes). */
final class Filters
{
    public function __construct(
        public readonly ?int $shopId = null,
        public readonly ?int $cityId = null,
        public readonly ?int $productId = null,
    ) {
    }

    /** Si hay algún filtro puesto (las metas del negocio solo se muestran sin filtros). */
    public function any(): bool
    {
        return $this->shopId !== null || $this->cityId !== null || $this->productId !== null;
    }

    public function toArray(): array
    {
        return ['shop_id' => $this->shopId, 'city_id' => $this->cityId, 'product_id' => $this->productId];
    }
}
