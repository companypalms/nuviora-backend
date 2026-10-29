<?php

namespace App\Services\Assistant\Tools;

use App\Models\User;
use App\Services\Assistant\Lookup;
use App\Services\Metrics\PersonName;
use Illuminate\Support\Facades\DB;

/** Quién hay en el sistema: los usuarios de cada rol, con su estado y su grupo de venta. Sin correos ni teléfonos. */
class UsersTool implements Tool
{
    private const ROLES = ['admin' => 'Admin', 'gerente' => 'Gerente', 'master' => 'Master', 'vendedoras' => 'Vendedor', 'repartidores' => 'Repartidor',
        'agencias' => 'Agencia', 'operadoras' => 'Operador logístico'];

    public function name(): string
    {
        return 'usuarios';
    }

    public function description(): string
    {
        return 'Los usuarios del sistema: cuántos hay de cada rol y la lista de un rol (o los que se llaman de una forma), con su estado y, las vendedoras, su grupo de venta. No incluye correos ni teléfonos.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rol' => ['type' => ['string', 'null'], 'enum' => [...array_keys(self::ROLES), null], 'description' => 'Solo ese rol. null = cuántos hay de cada uno.'],
                'nombre' => ['type' => ['string', 'null'], 'description' => 'Buscar por nombre. null = todos.'],
            ],
            'required' => ['rol', 'nombre'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando los usuarios…';
    }

    public function run(array $args): ToolResult
    {
        $users = User::with(['role:id,description', 'status:id,description'])->orderBy('names')
            ->when(isset(self::ROLES[$args['rol'] ?? '']), fn ($q) => $q->whereHas('role', fn ($r) => $r->where('description', self::ROLES[$args['rol']])))
            ->whereHas('role', fn ($r) => $r->where('description', '!=', 'Shopify'))
            ->get(['id', 'names', 'surnames', 'role_id', 'status_id']);
        $name = trim((string) ($args['nombre'] ?? ''));
        if ($name !== '') {
            $ids = array_column(Lookup::match($name, $users->mapWithKeys(fn ($u) => [$u->id => "{$u->names} {$u->surnames}"])->all(), 20), 'id');
            $users = $users->whereIn('id', $ids)->values();
        }
        $groups = DB::table('sales_group_members as m')->join('sales_groups as g', 'g.id', '=', 'm.sales_group_id')->whereNull('m.ended_at')
            ->whereIn('m.user_id', $users->pluck('id'))->get(['m.user_id', 'g.name', 'm.role'])->keyBy('user_id');

        $counts = $users->groupBy(fn ($u) => $u->role?->description ?? 'Sin rol')->map->count()->sortDesc()->all();
        $list = $users->take(80)->map(fn ($u) => array_filter([
            'nombre' => PersonName::of($u->names, $u->surnames),
            'rol' => $groups->get($u->id)?->role === 'leader' ? 'Líder' : $u->role?->description,
            'grupo' => $groups->get($u->id)?->name,
            'estado' => $u->status?->description ?? 'Activo',
        ]))->all();

        return new ToolResult(array_filter([
            'cuantos_por_rol' => $counts,
            'personas' => isset(self::ROLES[$args['rol'] ?? '']) || $name !== '' ? $list : null,
        ]), [
            ['type' => 'link', 'label' => 'Abrir Usuarios', 'to' => '/users'],
        ]);
    }
}
