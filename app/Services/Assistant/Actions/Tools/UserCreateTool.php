<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Models\Role;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Metrics\PersonName;
use App\Services\Users\UserAccounts;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Crear una cuenta (paso 7), lo mismo que Usuarios › Agregar (Users\UserAccounts::create). Solo vendedoras, agencias,
 * repartidores y operadoras (lo decidió el dueño). La contraseña la inventa el sistema al confirmar y el dueño la ve una
 * sola vez en la tarjeta (Outcome::$secret): no se guarda en la tarjeta ni la ve la IA.
 */
class UserCreateTool extends ChangeTool
{
    private const HINTS = [
        'Vendedor' => 'Para que reciba órdenes: agrégala a su tienda (Tiendas) y a su grupo (Reparto › Grupos y %).',
        'Agencia' => 'Se crea su almacén. Para que reciba órdenes: ponla en sus ciudades (Tiendas, ciudades y agencias).',
        'Repartidor' => 'Entra con la app Nuviora Rider.',
        'Operador logístico' => 'Asígnala a su centro en Logística propia.',
    ];

    public function __construct(private UserAccounts $accounts)
    {
    }

    public function name(): string
    {
        return 'proponer_usuario';
    }

    public function description(): string
    {
        return 'Propone crear la cuenta de una vendedora, una agencia, un repartidor o una operadora logística (como Usuarios › Agregar). Hace falta: nombre, correo y rol. La contraseña la crea el sistema y el dueño la ve una sola vez al confirmar: tú nunca la sabes ni la pidas. Admin, Master y Gerente no se crean aquí: que lo haga en la pantalla de Usuarios. El dueño confirma en una tarjeta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nombre' => ['type' => 'string', 'description' => 'El nombre (o el nombre de la agencia).'],
                'apellido' => ['type' => ['string', 'null']],
                'correo' => ['type' => 'string', 'description' => 'El correo con que va a entrar.'],
                'rol' => ['type' => 'string', 'enum' => array_keys(UserAccounts::ASSISTANT_ROLES)],
                'telefono' => ['type' => ['string', 'null']],
            ],
            'required' => ['nombre', 'apellido', 'correo', 'rol', 'telefono'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Preparando la cuenta…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $roleName = UserAccounts::ASSISTANT_ROLES[$args['rol'] ?? ''] ?? null;
        if ($roleName === null) {
            throw new ToolProblem('Solo puedo crear vendedoras, agencias, repartidores u operadoras. Admin, Master y Gerente se crean en la pantalla de Usuarios.');
        }
        $roleId = Role::where('description', $roleName)->value('id');
        if (!$roleId) {
            throw new ToolProblem("El rol {$roleName} no existe en el sistema.");
        }
        $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $data = [
            'names' => $clean($args['nombre'] ?? null),
            'surnames' => $clean($args['apellido'] ?? null),
            'email' => Str::lower((string) $clean($args['correo'] ?? null)),
            'phone' => $clean($args['telefono'] ?? null),
            'role_id' => (int) $roleId,
        ];
        $validator = Validator::make($data, UserAccounts::CREATE_RULES, [
            'names.required' => '¿Cómo se llama?',
            'email.required' => '¿Con qué correo va a entrar?',
            'email.email' => 'Ese correo no es válido.',
            'email.unique' => 'Ya hay una cuenta con ese correo.',
        ]);
        if ($validator->fails()) {
            throw new ToolProblem(implode(' ', $validator->errors()->all()));
        }
        $name = PersonName::of($data['names'], $data['surnames']);

        return new Proposal(
            'Crear una cuenta',
            "Crear la cuenta de {$name} ({$roleName}), {$data['email']}",
            Proposal::lines([
                Proposal::line('Nombre', $name),
                Proposal::line('Rol', $roleName),
                Proposal::line('Correo', $data['email']),
                Proposal::line('Teléfono', $data['phone']),
                Proposal::line('Contraseña', 'La crea el sistema: la ves una sola vez al confirmar'),
            ]),
            ['data' => $data],
            [],
            array_values(array_filter([self::HINTS[$roleName] ?? null, 'Al confirmar te muestro la contraseña una sola vez: cópiala para dársela. Después la puede cambiar.'])),
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $password = Str::password(12, true, true, false);
        $user = $this->accounts->create($payload['data'], $password);
        $name = PersonName::of($user->names, $user->surnames);

        return new Outcome(
            "Cuenta creada: {$name} entra con {$user->email}.",
            ['label' => 'Ver los usuarios', 'to' => '/users'],
            ['label' => "Contraseña de {$name}", 'value' => $password, 'note' => 'Cópiala ahora y dásela: no se vuelve a mostrar.'],
        );
    }
}
