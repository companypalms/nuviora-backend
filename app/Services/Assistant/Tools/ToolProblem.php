<?php

namespace App\Services\Assistant\Tools;

use RuntimeException;

/**
 * Los parámetros de una herramienta no sirven (un nombre que no existe, fechas al revés). El mensaje es para la IA, que
 * se lo explica al dueño o le pregunta: no es un error del sistema.
 */
class ToolProblem extends RuntimeException
{
}
