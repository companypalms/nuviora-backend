<?php

namespace App\Services\Assistant\Ai;

use App\Services\Assistant\Turn;

/**
 * Una IA para el asistente (Etapa 4). Hoy OpenAI y, sin clave en la PC de pruebas, el modo ejemplo; para cambiar de IA
 * se escribe otra clase con esta forma.
 */
interface AssistantModel
{
    /** Una vuelta: contesta o pide herramientas. Falla con RuntimeException (con un mensaje para guardar en el log). */
    public function next(Turn $turn): ModelReply;
}
