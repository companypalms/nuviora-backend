<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\StoreChat\StoreChat;
use App\Services\StoreChat\StoreChatRules;

/**
 * `.159` (dueño, 2026-11-18: "yo debo poder cambiar ese número desde mi chat de IA en mi dashboard también"): cambiar el
 * teléfono de atención al cliente que el chat de la tienda le da a quien ya compró (lo mismo que "Chat de la tienda ›
 * Ajustes").
 */
class StoreChatPhoneTool extends ChangeTool
{
    public function name(): string
    {
        return 'proponer_telefono_atencion';
    }

    public function description(): string
    {
        return 'Propone cambiar el teléfono de atención al cliente que el chat de la tienda les da a los clientes que ya compraron (devoluciones, cambios, reclamos o saber de su pedido). Vacío ("") para quitarlo. El dueño confirma en una tarjeta con antes y después.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['telefono' => ['type' => 'string', 'description' => 'El teléfono como lo dice el dueño, por ejemplo 0414-3581111; vacío para quitarlo.']],
            'required' => ['telefono'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Preparando el cambio del teléfono de atención al cliente…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $given = trim((string) ($args['telefono'] ?? ''));
        $phone = StoreChatRules::phone($given);
        if ($given !== '' && $phone === '') {
            throw new ToolProblem('Ese teléfono no se ve bien: pídele que lo escriba como 0414-3581111.');
        }
        $before = StoreChat::settings()['support_phone'];
        if ($phone === $before) {
            throw new ToolProblem($phone === '' ? 'El chat ya no tiene teléfono de atención al cliente.' : "El teléfono de atención al cliente ya es {$phone}.");
        }

        return new Proposal(
            'Cambiar el teléfono de atención al cliente del chat',
            $phone === '' ? 'Quitar el teléfono de atención al cliente del chat de la tienda' : "Teléfono de atención al cliente del chat de la tienda: {$phone}",
            Proposal::lines([Proposal::line('Teléfono', $phone !== '' ? $phone : 'Ninguno', $before !== '' ? $before : 'Ninguno')]),
            ['support_phone' => $phone],
            $phone === '' ? ['Sin teléfono, a quien ya compró el chat le dice que el equipo de atención al cliente lo ayuda, sin darle un número.'] : [],
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $saved = StoreChat::saveSettings(['support_phone' => (string) $payload['support_phone']]);

        return new Outcome($saved['support_phone'] !== ''
            ? "Listo: el chat de la tienda da el teléfono {$saved['support_phone']} a quien ya compró."
            : 'Listo: el chat de la tienda ya no da teléfono de atención al cliente.', ['label' => 'Ver el chat de la tienda', 'to' => '/chat-tienda']);
    }
}
