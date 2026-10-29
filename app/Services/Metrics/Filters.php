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

    public function toArray(): array
    {
        return ['shop_id' => $this->shopId, 'city_id' => $this->cityId, 'product_id' => $this->productId];
    }
}
