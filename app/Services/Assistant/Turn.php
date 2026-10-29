<?php

namespace App\Services\Assistant;

use App\Services\Assistant\Ai\ModelReply;

/**
 * Lo que la IA necesita para dar una vuelta (Etapa 4): las instrucciones, lo que se habló antes (solo texto), la pregunta
 * nueva con sus archivos, las herramientas y lo que pasó en las vueltas anteriores de esta misma respuesta (lo que pidió la
 * IA y lo que devolvió cada herramienta). No depende de ninguna IA en particular: cada una (OpenAI, el modo ejemplo) lo
 * traduce a su forma. PHP puro.
 */
final class Turn
{
    /**
     * @param array<int, array{role: string, text: string}> $history
     * @param array<int, array> $tools las definiciones de las herramientas (ToolCatalog::definitions)
     * @param array<int, array{reply: ModelReply, outputs: array<string, string>}> $steps
     * @param bool $mustAnswer la última vuelta: la IA tiene que contestar con lo que ya tiene, sin pedir más herramientas
     * @param array<int, array{type: string, name: string, label: string, mime?: string, data?: string, text?: string}> $files
     *   los archivos de la pregunta (Files\Attachments::parts): image y file (un PDF escaneado) con su contenido en base64;
     *   text, el texto leído de un PDF o un Excel
     */
    public function __construct(
        public readonly string $instructions,
        public readonly array $history,
        public readonly string $message,
        public readonly array $tools,
        public readonly array $steps = [],
        public readonly bool $mustAnswer = false,
        public readonly array $files = [],
    ) {
    }

    /** @param array<string, string> $outputs call_id => lo que devolvió la herramienta (JSON) */
    public function withStep(ModelReply $reply, array $outputs): self
    {
        return new self($this->instructions, $this->history, $this->message, $this->tools, [...$this->steps, ['reply' => $reply, 'outputs' => $outputs]], $this->mustAnswer, $this->files);
    }

    public function withMustAnswer(bool $mustAnswer): self
    {
        return new self($this->instructions, $this->history, $this->message, $this->tools, $this->steps, $mustAnswer, $this->files);
    }
}
