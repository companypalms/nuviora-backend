<?php

namespace App\Services\Assistant\Tools;

/**
 * Una herramienta del asistente (Etapa 4): algo que la IA puede consultar en el sistema. La IA nunca inventa un número:
 * lo pide a una herramienta, que usa los mismos cálculos de las pantallas (por ejemplo, el Panel del dueño).
 *
 * En este paso todas solo leen. Las que cambien algo (paso 5) van a proponer el cambio y esperar a que el dueño confirme.
 */
interface Tool
{
    /** El nombre que ve la IA: "ventas". */
    public function name(): string;

    /** Para qué sirve y cuándo usarla, para la IA. */
    public function description(): string;

    /** Los parámetros, en JSON Schema estricto (todos requeridos; los opcionales aceptan null). */
    public function parameters(): array;

    /** Lo que ve el dueño mientras se consulta: "Revisando las ventas…". */
    public function working(array $args): string;

    /** Consulta. Si los parámetros no sirven (un nombre que no existe), ToolProblem con el mensaje para la IA. */
    public function run(array $args): ToolResult;
}
