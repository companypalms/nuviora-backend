<?php

namespace App\Services\Assistant\Ai;

/**
 * Lo que devolvió la IA en una vuelta: el texto de la respuesta (si ya contestó) y las herramientas que pide usar.
 * "carry" es lo que cada IA necesita que se le devuelva en la vuelta siguiente (en OpenAI, su razonamiento y sus
 * llamadas); el asistente no lo mira.
 */
final class ModelReply
{
    /**
     * @param array<int, array{call_id: string, name: string, arguments: array}> $calls
     * @param array<int, array> $carry
     * @param array{input: int, cached: int, output: int} $usage
     */
    public function __construct(
        public readonly ?string $text,
        public readonly array $calls,
        public readonly array $carry,
        public readonly array $usage,
        public readonly string $model,
    ) {
    }
}
