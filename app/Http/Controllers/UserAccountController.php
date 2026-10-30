<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Metrics\PersonName;
use App\Services\Users\UserAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Desactivar y reactivar cuentas desde Configuración › Usuarios (paso 6). Solo el Admin (la ruta lo exige). */
class UserAccountController extends Controller
{
    public function __construct(private UserAccounts $accounts)
    {
    }

    /** GET /users/{user}/deactivation — para la ventana: si se puede, lo que tiene pendiente y su historial. */
    public function show(User $user): JsonResponse
    {
        $user->load('role');

        return response()->json(['status' => true, 'data' => [
            'user' => $this->summary($user),
            'problem' => $user->isDeactivated() ? null : $this->accounts->problem($user, Auth::user()),
            'pending' => $user->isDeactivated() ? [] : $this->accounts->pending($user),
            'history' => $this->accounts->history($user),
            'reason_min' => UserAccounts::REASON_MIN,
        ]]);
    }

    /** POST /users/{user}/deactivate { reason } */
    public function deactivate(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:300'], ['reason.required' => 'Escribe el motivo: queda en su historial.']);
        $user = $this->accounts->deactivate($user, Auth::user(), $data['reason']);

        return response()->json(['status' => true, 'message' => 'Cuenta desactivada: ' . PersonName::of($user->names, $user->surnames) . ' ya no puede entrar.', 'data' => $this->summary($user)]);
    }

    /** POST /users/{user}/reactivate */
    public function reactivate(User $user): JsonResponse
    {
        $user = $this->accounts->reactivate($user, Auth::user());

        return response()->json(['status' => true, 'message' => 'Cuenta reactivada: ' . PersonName::of($user->names, $user->surnames) . ' ya puede entrar con su misma contraseña.', 'data' => $this->summary($user)]);
    }

    private function summary(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => PersonName::of($user->names, $user->surnames),
            'role' => $user->role?->description,
            'deactivated_at' => $user->deactivated_at?->toIso8601String(),
            'deactivation_reason' => $user->deactivation_reason,
        ];
    }
}
