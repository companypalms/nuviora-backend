<?php

namespace App\Services\Assistant\Tools;

/**
 * Lo que devuelve una herramienta, en dos partes:
 * - data: lo que lee la IA, corto y ya en palabras ("$1.234,56", "+5 %"), sin teléfonos;
 * - display: lo que se ve debajo de la respuesta (tarjetas, gráficas, tablas, listas o un enlace). Se arma con los datos
 *   del sistema y no se le manda a la IA.
 */
final class ToolResult
{
    public function __construct(public readonly array $data, public readonly array $display = [])
    {
    }

    public static function problem(string $message): self
    {
        return new self(['problema' => $message]);
    }
}
