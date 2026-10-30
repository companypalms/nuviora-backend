<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Models\Warehouse;
use App\Models\WarehouseType;
use App\Services\Users\UserAccounts;

class AuthController extends Controller
{
    // 🔒 Roles cuyas cuentas solo Admin/Master pueden crear o editar
    private const PROTECTED_ROLES = ['Admin', 'Master'];

    private function isSuperAdmin(?User $user): bool
    {
        return in_array($user?->role?->description, self::PROTECTED_ROLES, true);
    }

    // POST /users (la ruta ya exige Admin, Gerente o Master)
    public function store(Request $request, UserAccounts $accounts)
    {
        $request->validate(UserAccounts::CREATE_RULES + ['password' => 'required|string|min:6', 'role_id' => 'required|exists:roles,id']);

        // 🔒 Un Gerente no puede crear cuentas de Admin o Master
        $newRole = Role::find($request->role_id);
        // El rol Gerente se quitó (Fran, 2026-10-02): no se crean cuentas nuevas con él
        if ($newRole?->description === 'Gerente') {
            return response()->json(['status' => false, 'message' => 'El rol Gerente ya no se usa. Elige otro rol.'], 422);
        }
        if (in_array($newRole?->description, self::PROTECTED_ROLES, true) && !$this->isSuperAdmin(Auth::user())) {
            return response()->json(['status' => false, 'message' => 'No tienes permisos para crear usuarios con ese rol'], 403);
        }

        // Crear la cuenta (con su almacén si es Agencia) lo comparte con el asistente (paso 7)
        $user = $accounts->create($request->only(['names', 'surnames', 'email', 'phone', 'address', 'delivery_cost', 'role_id']), $request->password);

        return response()->json([
            'status' => true,
            'message' => 'Usuario creado exitosamente',
            'user'   => $user,
        ], 201);
    }

    /**
     * GET /users?estado=activos|desactivados&q=&rol= — todas las cuentas (menos la del sistema de Shopify), 20 por página.
     * Antes solo salían las que tenían el estado viejo "Activo", que muchas no tienen (por ejemplo, los repartidores
     * creados desde su pestaña). Paso 6.
     */
    public function get_all_users(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $role = trim((string) $request->query('rol', ''));

        $users = User::with(['role', 'deactivator:id,names,surnames'])
            ->where(fn ($q) => $q->whereNull('role_id')->orWhereHas('role', fn ($r) => $r->where('description', '!=', 'Shopify')))
            ->when($request->query('estado') === 'desactivados', fn ($q) => $q->whereNotNull('deactivated_at'), fn ($q) => $q->whereNull('deactivated_at'))
            ->when($role !== '', fn ($q) => $q->whereHas('role', fn ($r) => $r->where('description', $role)))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('names', 'like', "%{$search}%")
                ->orWhere('surnames', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();
        $users->getCollection()->each->makeVisible('deactivation_reason');

        return response()->json(['status' => true, 'data' => $users]);
    }

    /** Las listas para elegir a quién asignar no traen cuentas desactivadas; los filtros del historial piden ?include_inactive=1. */
    private function onlyActive($query, Request $request)
    {
        return $request->boolean('include_inactive') ? $query : $query->active();
    }
    public function deliverers(Request $request)
    {
        $roleId = Role::where('description', '=', 'Repartidor')->value('id');
        $q = trim((string) $request->get('search', ''));

        $users = $this->onlyActive(User::query(), $request)
            ->where('role_id', '=', $roleId)
            ->when(Auth::user()->role?->description === 'Agencia', function ($query) {
                $query->where('agency_id', '=', Auth::id());
            })
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($qq) use ($q) {
                    $qq->where('names', 'like', "%$q%")
                        ->orWhere('surnames', 'like', "%$q%")
                        ->orWhere('email', 'like', "%$q%");
                });
            })
            ->orderBy('names')
            ->select('id', 'names', 'surnames', 'email', 'created_at', 'deactivated_at')
            ->paginate(20);

        return response()->json([
            'status' => true,
            'data'   => $users->items(),
            'meta'   => [
                'current_page' => $users->currentPage(),
                'total'        => $users->total(),
                'last_page'    => $users->lastPage(),
            ],
        ]);
    }

    // POST /users/deliverers
    public function storeDeliverer(Request $request)
    {
        $request->validate([
            'names'    => 'required|string|max:100',
            'surnames' => 'nullable|string|max:100',
            'phone' => 'required|string|max:100',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
        ]);

        $roleId = Role::where('description', '=', 'Repartidor')->value('id');

        $user = User::create([
            'names'     => $request->names,
            'surnames'  => $request->surnames,
            'email'     => $request->email,
            'phone'     => $request->phone,
            'password'  => Hash::make($request->password),
            'role_id'   => $roleId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Repartidor creado',
            'user'   => $user,
        ], 201);
    }

    // PUT /users/deliverers/{user}
    public function updateDeliverer(Request $request, User $user)
    {
        // asegurar que realmente es repartidor
        if ($user->role?->description !== 'Repartidor') {
            return response()->json(['status' => false, 'message' => 'No es un repartidor'], 422);
        }

        $request->validate([
            'names'    => 'required|string|max:100',
            'surnames' => 'nullable|string|max:100',
            'email'    => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'nullable|string|min:6',
        ]);

        $payload = [
            'names'    => $request->names,
            'surnames' => $request->surnames,
            'email'    => $request->email,
        ];
        if ($request->filled('password')) {
            $payload['password'] = Hash::make($request->password);
        }

        $user->update($payload);

        return response()->json([
            'status' => true,
            'message' => 'Repartidor actualizado',
            'user'   => $user,
        ]);
    }

    /**
     * DELETE /users/deliverers/{user} — ahora desactiva (paso 6). Antes borraba la cuenta de verdad, y con ella sus
     * ganancias, su stock y sus registros (o fallaba si ya tenía pagos). La pantalla usa POST /users/{id}/deactivate.
     */
    public function destroyDeliverer(Request $request, User $user, UserAccounts $accounts)
    {
        if ($user->role?->description !== 'Repartidor') {
            return response()->json(['status' => false, 'message' => 'No es un repartidor'], 422);
        }

        $accounts->deactivate($user, Auth::user(), (string) $request->input('reason', 'Eliminado desde Repartidores'));

        return response()->json([
            'status'  => true,
            'message' => 'Repartidor desactivado',
        ]);
    }
    /**
     * Login de usuario
     */
    public function login(Request $request)
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
        }
        $user = User::with('role')->where('email', '=', $request['email'])->firstOrFail();
        if ($user->isDeactivated()) {
            return response()->json(['status' => false, 'message' => 'Tu cuenta está desactivada. Habla con el administrador.'], 403);
        }
        $token = $user->createToken('auth_token')->plainTextToken;
        $user->token = $token;
        $user->leader_group = $this->leaderGroupRef($user);
        $logs = Log::create([
            'description' => "El usuario $user->names $user->surnames ($user->id) inició sesión. ($user->email)",
            'impact' => 'Normal',
            'author' => 'Sistema',
        ]);
        $logs->user()->associate($user);
        $logs->save();
        return response()->json([
            'status' => true,
            'message' => 'Bienvenido ' . $user->names . ' ' . $user->surnames,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }
    /**
     * Cerrar sesion
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        $logs = Log::create([
            'description' => "El usuario $user->first_name $user->lastname ($user->document) cerró sesión. ($user->email)",
            'impact' => 'Leve',
            'author' => 'Sistema',
        ]);
        $logs->user()->associate($user);
        $logs->save();
        $request->user()->currentAccessToken()->delete();
        return [
            'status' => true,
            'message' => 'Has cerrado sesion exitosamente'
        ];
    }
    public function agents(Request $request)
    {
        $agents = $this->onlyActive(User::query(), $request)->whereHas('role', function ($q) {
            $q->where('description', '=', 'Vendedor');
        })->get();

        return response()->json([
            'status' => true,
            'data' => $agents
        ]);
    }

    public function usersByRole(Request $request, $roleDescription)
    {
        $isAgency = strtolower(trim($roleDescription)) === 'agencia';

        $users = $this->onlyActive(User::query(), $request)->whereHas('role', function ($q) use ($roleDescription) {
            $q->where('description', '=', $roleDescription);
        })
        // Para Agencias incluimos sus ciudades para poder etiquetar "Agencia: Ciudad X".
        ->when($isAgency, function ($q) {
            $q->with(['cities' => function ($cq) {
                $cq->orderBy('id');
            }]);
        })
        ->get();

        if ($isAgency) {
            $users->each(function ($user) {
                // Ciudad principal = primera ciudad asociada (orden por id).
                $primary = $user->cities->first();
                $user->primary_city = $primary?->name;
            });
        }

        return response()->json([
            'status' => true,
            'data' => $users
        ]);
    }
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'names' => 'required|string|max:255',
            'surnames' => 'string|max:255',
            'phone' => 'required|string|max:255|unique:users',
            'email' => 'required|string|email|max:255|unique:users',
            'address' => 'required|string',
            'password' => 'required|string|min:8',
            'level' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 400);
        }

        $user = User::create([
            'names' => $request->names,
            'surnames' => $request->surnames,
            'email' => $request->email,
            'address' => $request->address,
            'password' => Hash::make($request->password),
            'color' => '#C0EA0F',
        ]);
        // Obtener status activo o crear status si no existe
        $status = Status::firstOrNew(['description' => 'Activo']);
        $status->save();

        // Se asocia el status al usuario
        $user->status()->associate($status);

        // Obtener rol cliente o crear rol si no existe
        $role = Role::firstOrNew(['description' => 'Usuario']);
        $role->save();

        // Se asocia el rol al usuario
        $user->role()->associate($role);
        // Se guarda el usuario
        $user->save();

        // Token de auth
        $token = $user->createToken("auth_token")->plainTextToken;

        return response()->json(['data' => $user, 'token' => $token, 'token_type' => 'Bearer', 'status' => true]);
    }
    /**
     * Registrar administrador de condominios
     */
    public function register_master(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'names' => 'required|string|max:255',
            'surnames' => 'string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 400);
        }

        $user = User::create([
            'names' => $request->names,
            'surnames' => $request->surnames,
            'email' => $request->email,
            'address' => $request->address,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'theme' => 'light',
            'color' => '#0073ff',
        ]);
        // Obtener status activo o crear status si no existe
        $status = Status::firstOrNew(['description' => 'Activo']);
        $status->save();

        // Se asocia el status al usuario
        $user->status()->associate($status);

        // Obtener rol cliente o crear rol si no existe
        $role = Role::firstOrNew(['description' => 'Admin']);
        $role->save();

        // Se asocia el rol al usuario
        $user->role()->associate($role);
        // Se guarda el usuario
        $user->save();

        // Token de auth
        $token = $user->createToken("auth_token")->plainTextToken;

        return response()->json(['data' => $user, 'token' => $token, 'token_type' => 'Bearer', 'status' => true]);
    }
    /**
     * Registrar administrador de condominios
     */
    public function register_agent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'names' => 'required|string|max:255',
            'surnames' => 'string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 400);
        }

        $user = User::create([
            'names' => $request->names,
            'surnames' => $request->surnames,
            'email' => $request->email,
            'address' => $request->address,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'theme' => 'light',
            'color' => '#0073ff',
        ]);
        // Obtener status activo o crear status si no existe
        $status = Status::firstOrNew(['description' => 'Activo']);
        $status->save();

        // Se asocia el status al usuario
        $user->status()->associate($status);

        // Obtener rol cliente o crear rol si no existe
        $role = Role::firstOrNew(['description' => 'Vendedor']);
        $role->save();

        // Se asocia el rol al usuario
        $user->role()->associate($role);
        // Se guarda el usuario
        $user->save();

        // Token de auth
        $token = $user->createToken("auth_token")->plainTextToken;

        return response()->json(['data' => $user, 'token' => $token, 'token_type' => 'Bearer', 'status' => true]);
    }

    public function edit_color(Request $request, User $user)
    {
        // 🔒 Solo el propio usuario (o Admin/Master)
        if (Auth::id() !== $user->id && !$this->isSuperAdmin(Auth::user())) {
            return response()->json(['status' => false, 'message' => 'No tienes permisos para realizar esta acción'], 403);
        }
        if (!$request->color) {
            return response()->json(['status' => false, 'message' => 'El color es obligatorio'], 400);
        }
        $user->color = $request->color;
        $user->save();

        return response()->json(['status' => true, 'message' => 'Se ha cambiado el color'], 200);
    }
    public function edit_theme(Request $request, User $user)
    {
        // 🔒 Solo el propio usuario (o Admin/Master)
        if (Auth::id() !== $user->id && !$this->isSuperAdmin(Auth::user())) {
            return response()->json(['status' => false, 'message' => 'No tienes permisos para realizar esta acción'], 403);
        }
        if (!$request->theme) {
            return response()->json(['status' => false, 'message' => 'El tema es obligatorio'], 400);
        }
        $user->theme = $request->theme;
        $user->save();

        return response()->json(['status' => true, 'message' => 'Se ha cambiado el tema'], 200);
    }
    public function get_logged_user_data(Request $request)
    {
        $data = $request->user();
        $user = User::with('role')->where('id', '=', $data->id)->first();
        $user->leader_group = $this->leaderGroupRef($user);
        return response()->json(['user' => $user]);
    }

    /** Si es Líder, su grupo ({id, name}): el front muestra "Mi grupo". El permiso real lo valida MyGroupController. */
    private function leaderGroupRef(User $user): ?array
    {
        $group = $user->ledGroup();

        return $group ? ['id' => $group->id, 'name' => $group->name] : null;
    }
    public function edit_user_data(Request $request, User $user)
    {
        // 🔒 Cada quien edita sus propios datos; supervisión edita a los demás,
        // pero un Gerente no puede tocar cuentas Admin/Master (evita robar la cuenta cambiando el email).
        $authUser = Auth::user();
        $isSelf = $authUser->id === $user->id;
        $isSupervisor = in_array($authUser->role?->description, ['Admin', 'Gerente', 'Master'], true);

        if (!$isSelf && !$isSupervisor) {
            return response()->json(['status' => false, 'message' => 'No tienes permisos para editar este usuario'], 403);
        }
        if (!$isSelf && in_array($user->role?->description, self::PROTECTED_ROLES, true) && !$this->isSuperAdmin($authUser)) {
            return response()->json(['status' => false, 'message' => 'No tienes permisos para editar este usuario'], 403);
        }
        // Campos operativos: solo supervisión
        if (!$isSupervisor && ($request->has('delivery_cost') || $request->has('can_handle_no_stock') || $request->has('is_lite_view'))) {
            return response()->json(['status' => false, 'message' => 'No tienes permisos para cambiar esos campos'], 403);
        }

        $validator = Validator::make($request->all(), [
            'phone' => 'string|max:255',
            'names' => 'string|max:255',
            'surnames' => 'string|max:255',
            'email' => 'string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'string|min:8',
            'address' => 'string|min:8',
            'delivery_cost' => 'numeric|min:0',
            'is_lite_view' => 'nullable',
            'can_handle_no_stock' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 400);
        }
        try {
            //code...
            if ($request->phone != '') {
                $user->phone = $request->phone;
            }
            if ($request->names != '') {
                $user->names = $request->names;
            }
            if ($request->surnames != '') {
                $user->surnames = $request->surnames;
            }
            if ($request->email != '') {
                $user->email = $request->email;
            }
            if ($request->address != '') {
                $user->address = $request->address;
            }
            if ($request->has('delivery_cost')) {
                $user->delivery_cost = $request->delivery_cost;
            }
            if ($request->has('is_lite_view')) {
                $user->is_lite_view = filter_var($request->is_lite_view, FILTER_VALIDATE_BOOLEAN);
            }
            if ($request->has('can_handle_no_stock')) {
                $user->can_handle_no_stock = filter_var($request->can_handle_no_stock, FILTER_VALIDATE_BOOLEAN);
            }
            $user->save();
            return response()->json(['status' => true, 'message' => 'Se ha editado el usuario', 'data' => $user], 200);
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json(['status' => false, 'message' => 'Error al editar el usuario', 'error' => $th->getMessage()], 500);
        }
    }

    public function edit_user(Request $request, User $user)
    {

        if ($request->password != $request->confirmarPassword) {
            return response()->json(['status' => false, 'errors' => ['password' => 'Las contraseñas no coinciden']], 400);
        }

        $validator = Validator::make($request->all(), [
            'phone' => 'string|max:255',
            // 'email' => 'string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 400);
        }
        $prev_phone = $user->phone;
        $new_password = $user->password;
        if ($request->phone != '') {
            $user->phone = $request->phone;
        }
        if ($request->password != '' && $request->confirmarPassword != '') {
            $new_password = $request->password;
            $user->password = Hash::make($request->password);
        }
        $user->save();
        $logs = Log::create([
            'description' => "El usuario $user->names $user->surnames ($user->document) edito sus datos, Tlf: $prev_phone, Pass: $new_password. ($user->email)",
            'impact' => 'Alta',
            'author' => 'Sistema',
        ]);
        $logs->user()->associate($user);
        $logs->save();
        return response()->json(['status' => true, 'message' => 'Se ha editado el usuario', 'user' => $user], 200);
    }
    // PUT /user/{user}/change/password
    public function edit_password(Request $request, User $user)
    {
        // Solo Admin puede cambiar contraseña de otros
        $authUser = Auth::user();
        if ($authUser->role?->description !== 'Admin') {
            return response()->json([
                'status' => false,
                'message' => 'No tienes permisos para realizar esta acción'
            ], 403);
        }

        $request->validate([
            'new_password' => 'required|string|min:6',
        ]);

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'Contraseña actualizada exitosamente',
        ]);
    }
}
