<?php

namespace App\Http\Middleware;

use App\Models\Status;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logística propia (§3; pedido de Fran del 24/10, docs/LogisticaPropia_Seguridad_Analisis.md): el rider y el operador
 * logístico solo llegan a lo suyo. Con el mismo login se podía llamar directo a la API y cambiar los pagos de la orden,
 * reprogramarla, pasársela a otro, tocar el catálogo… Aquí se invierte: todo les da 403 salvo esta lista.
 * - Rider: su app (/rider/*) y las dos llamadas de la entrega (§12.1): subir el comprobante y marcar "Entregado".
 * - Operador: su torre de control (/logistics/*).
 * - Los editores (Etapa 5, paso 5a): sus encargos (/editor/*).
 * - El editor de landings (paso 6b): sus encargos de landing (/landing-editor/*).
 * - Todos: su sesión y su perfil.
 * Cada ruta de la lista sigue con sus propios controles (rol, orden suya, centro suyo).
 */
class RestrictLimitedRoles
{
    private const COMMON = [
        'api/user/data',
        'api/logout',
        'api/user/*/change/*',
    ];

    private const RIDER = 'repartidor';
    private const OPERATOR = 'operador logístico';
    private const EDITORS = ['editor líder', 'editor de video'];
    private const LANDING_EDITOR = 'editor de landings';

    public function handle(Request $request, Closure $next): Response
    {
        $role = strtolower((string) $request->user()?->role?->description);
        $editor = in_array($role, self::EDITORS, true);
        if ($role !== self::RIDER && $role !== self::OPERATOR && !$editor && $role !== self::LANDING_EDITOR) {
            return $next($request);
        }

        if ($request->is(...self::COMMON)) {
            return $next($request);
        }
        if ($role === self::OPERATOR && $request->is('api/logistics/*')) {
            return $next($request);
        }
        if ($editor) {
            return $request->is('api/editor/*') ? $next($request) : $this->deny('Tu usuario solo tiene acceso a tus encargos de video.');
        }
        if ($role === self::LANDING_EDITOR) {
            return $request->is('api/landing-editor/*') ? $next($request) : $this->deny('Tu usuario solo tiene acceso a tus encargos de landing.');
        }
        if ($role === self::RIDER) {
            if ($request->is('api/rider/*')) {
                return $next($request);
            }
            if ($request->isMethod('POST') && $request->is('api/orders/*/payment-receipt')) {
                return $next($request);
            }
            if ($request->isMethod('PUT') && $request->is('api/orders/*/status')) {
                // Las novedades van por /rider (§13) y la salida la confirma el operador (§11): el rider solo entrega
                $target = $request->input('status') ?? Status::whereKey($request->input('status_id'))->value('description');
                if ($target === 'Entregado') {
                    return $next($request);
                }

                return $this->deny('El rider solo puede marcar la orden como "Entregado".');
            }
        }

        return $this->deny();
    }

    private function deny(string $message = 'Tu usuario solo tiene acceso a su pantalla de logística.'): Response
    {
        return response()->json(['status' => false, 'message' => $message], 403);
    }
}
