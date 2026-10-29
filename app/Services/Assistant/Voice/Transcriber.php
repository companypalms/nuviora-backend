<?php

namespace App\Services\Assistant\Voice;

/**
 * Convierte en texto lo que el dueño le dijo al micrófono del asistente (Etapa 4, paso 4). El texto va a la caja del chat:
 * él lo revisa y lo envía. Detrás de esta interfaz, para poder cambiar de IA.
 */
interface Transcriber
{
    /**
     * @param string $path el audio (se borra al terminar la petición: no se guarda)
     * @param string $format webm | mp4 | m4a | mp3 | wav
     */
    public function transcribe(string $path, string $format): string;
}
