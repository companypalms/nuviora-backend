<?php

namespace App\Services\Assistant\Voice;

/**
 * El micrófono en modo ejemplo (la PC de pruebas, sin clave de OpenAI): no convierte la voz de verdad, pone una pregunta
 * de ejemplo en la caja. Así se prueba grabar, la caja y enviar sin gastar.
 */
class ExampleTranscriber implements Transcriber
{
    public const TEXT = '¿Cuánto vendimos esta semana?';

    public function transcribe(string $path, string $format): string
    {
        return self::TEXT;
    }
}
