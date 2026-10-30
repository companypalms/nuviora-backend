<?php

namespace App\Services\Assistant\Actions;

use App\Services\Assistant\Tools\Tool;
use App\Services\Assistant\Tools\ToolResult;

/**
 * Una herramienta que cambia algo del sistema (Etapa 4, paso 5). La IA nunca cambia nada directo: la herramienta revisa
 * los datos con las mismas reglas de la pantalla y arma la tarjeta (propose); se guarda en "Lo que hizo el asistente" y
 * solo cuando el dueño toca Confirmar se hace (execute), con los mismos servicios que usa la pantalla.
 */
abstract class ChangeTool implements Tool
{
    private ?ActionContext $context = null;

    /**
     * Revisa y arma la tarjeta. Si falta algo o no se puede, ToolProblem (o ValidationException) con el motivo para la
     * IA, que se lo pregunta o se lo explica al dueño. Se llama otra vez al confirmar: tiene que dar lo mismo.
     */
    abstract public function propose(array $args, ActionContext $context): Proposal;

    /** Lo hace (ActionDesk ya abrió la transacción). */
    abstract public function execute(array $payload, ActionContext $context): Outcome;

    /** La herramienta para una respuesta en particular (ToolCatalog::forAnswer). */
    public function withContext(ActionContext $context): static
    {
        $copy = clone $this;
        $copy->context = $context;

        return $copy;
    }

    /** Lo que llama la conversación: propone, nunca hace. */
    public function run(array $args): ToolResult
    {
        if ($this->context === null) {
            return ToolResult::problem('Ahora no se pueden proponer cambios.');
        }

        return app(ActionDesk::class)->propose($this, $args, $this->context);
    }
}
