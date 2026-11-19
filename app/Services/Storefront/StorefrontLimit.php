<?php

namespace App\Services\Storefront;

use RuntimeException;

/** `.164`: un tope del formulario de la tienda (el mismo teléfono muchas veces en un día, o el máximo del día); el mensaje va al cliente tal cual. */
class StorefrontLimit extends RuntimeException
{
}
