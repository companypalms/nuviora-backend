<?php

namespace App\Services\Storefront;

use RuntimeException;

/** `.172`: se acabó el stock de esa ciudad mientras el cliente llenaba el formulario (el mensaje lleva el nombre de la ciudad). */
class StorefrontNoStock extends RuntimeException
{
    public function __construct(public readonly string $city)
    {
        parent::__construct($city);
    }
}
