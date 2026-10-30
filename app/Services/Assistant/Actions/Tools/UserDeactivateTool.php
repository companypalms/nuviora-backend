<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Models\User;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Lookup;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Metrics\PersonName;
use App\Services\Users\UserAccounts;

/**
 * Desactivar una cuenta (paso 7), lo mismo que Usuarios › Desactivar (Users\UserAccounts::deactivate, paso 6): ya no
 * entra ni recibe trabajo, y nada se borra. La tarjeta muestra lo que tiene pendiente.
 */
class UserDeactivateTool extends ChangeTool
{
    public function __construct(private UserAccounts $accounts)
    {
    }

    public function name(): string
    {
        return 'proponer_desactivar_usuario';
    }

    public function description(): string
    {
        return '"Eliminar" o "dar de baja" a alguien es desactivar su cuenta: ya no entra ni recibe órdenes, leads ni rondas, y su historial queda (se puede reactivar). Hace falta el motivo: si no lo dijo, pregúntalo. La tarjeta muestra lo que tiene pendiente (órdenes, efectivo…). No se puede con el Admin, el Master ni la líder de un grupo. El dueño confirma en una tarjeta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'persona' => ['type' => 'string', 'description' => 'El nombre, como lo dijo el dueño.'],
                'motivo' => ['type' => ['string', 'null'], 'description' => 'Por qué (queda en su historial). Si no lo dijo, null (se le pregunta).'],
            ],
            'required' => ['persona', 'motivo'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando la cuenta…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $user = self::person((string) ($args['persona'] ?? ''), false);
        if ($problem = $this->accounts->problem($user, $context->user)) {
            throw new ToolProblem($problem);
        }
        $reason = trim((string) ($args['motivo'] ?? ''));
        if (mb_strlen($reason) < UserAccounts::REASON_MIN) {
            throw new ToolProblem('¿Por qué la desactivas? Queda en su historial.');
        }
        $name = PersonName::of($user->names, $user->surnames);
        $pending = $this->accounts->pending($user);

        return new Proposal(
            'Desactivar una cuenta',
            "Desactivar la cuenta de {$name} ({$user->role?->description}): {$reason}",
            Proposal::lines([
                Proposal::line('Persona', $name),
                Proposal::line('Rol', $user->role?->description),
                Proposal::line('Motivo', $reason),
            ]),
            ['user_id' => $user->id, 'reason' => $reason],
            [],
            array_column($pending, 'text'),
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $user = $this->accounts->deactivate(User::findOrFail($payload['user_id']), $context->user, $payload['reason']);

        return new Outcome('Cuenta desactivada: ' . PersonName::of($user->names, $user->surnames) . ' ya no puede entrar.', ['label' => 'Ver los usuarios', 'to' => '/users']);
    }

    /** Una cuenta por su nombre: las activas ($deactivated = false) o las desactivadas. Sin la del sistema de Shopify. */
    public static function person(string $name, bool $deactivated): User
    {
        $users = User::with('role:id,description')
            ->whereHas('role', fn ($q) => $q->where('description', '!=', 'Shopify'))
            ->when($deactivated, fn ($q) => $q->whereNotNull('deactivated_at'), fn ($q) => $q->whereNull('deactivated_at'))
            ->orderBy('names')->get(['id', 'names', 'surnames', 'role_id', 'deactivated_at']);
        if ($users->isEmpty()) {
            throw new ToolProblem($deactivated ? 'No hay cuentas desactivadas.' : 'No hay cuentas activas.');
        }
        $labels = $users->mapWithKeys(fn (User $u) => [$u->id => PersonName::of($u->names, $u->surnames) . " ({$u->role?->description})"])->all();
        $found = Lookup::match($name, $labels, 6);
        if (count($found) === 1) {
            return User::with('role')->findOrFail($found[0]['id']);
        }

        throw new ToolProblem($found !== []
            ? 'Hay varias personas parecidas: ' . implode(', ', array_column($found, 'name')) . '. Pregúntale cuál.'
            : "No encontré a \"{$name}\" entre las cuentas " . ($deactivated ? 'desactivadas' : 'activas') . '.');
    }
}
