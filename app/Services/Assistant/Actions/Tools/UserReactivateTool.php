<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Models\User;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Metrics\PersonName;
use App\Services\Users\UserAccounts;

/**
 * Reactivar una cuenta desactivada (paso 7), lo mismo que Usuarios › Desactivados › Reactivar (Users\UserAccounts):
 * vuelve a entrar con su misma contraseña y a recibir trabajo.
 */
class UserReactivateTool extends ChangeTool
{
    public function __construct(private UserAccounts $accounts)
    {
    }

    public function name(): string
    {
        return 'proponer_reactivar_usuario';
    }

    public function description(): string
    {
        return 'Propone reactivar una cuenta desactivada: vuelve a entrar con su misma contraseña, a recibir trabajo y a su roster de siempre al abrir la jornada. El dueño confirma en una tarjeta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['persona' => ['type' => 'string', 'description' => 'El nombre, como lo dijo el dueño.']],
            'required' => ['persona'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando la cuenta…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $user = UserDeactivateTool::person((string) ($args['persona'] ?? ''), true);
        $name = PersonName::of($user->names, $user->surnames);

        return new Proposal(
            'Reactivar una cuenta',
            "Reactivar la cuenta de {$name} ({$user->role?->description})",
            Proposal::lines([
                Proposal::line('Persona', $name),
                Proposal::line('Rol', $user->role?->description),
                Proposal::line('Desactivada', $user->deactivated_at?->format('d/m/Y') . ($user->deactivation_reason ? ": {$user->deactivation_reason}" : '')),
            ]),
            ['user_id' => $user->id],
            [],
            ['Vuelve a entrar con su misma contraseña y a recibir trabajo. Al abrir la jornada vuelve a su roster de siempre.'],
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $user = $this->accounts->reactivate(User::findOrFail($payload['user_id']), $context->user);

        return new Outcome('Cuenta reactivada: ' . PersonName::of($user->names, $user->surnames) . ' ya puede entrar con su misma contraseña.', ['label' => 'Ver los usuarios', 'to' => '/users']);
    }
}
