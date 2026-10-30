<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\RestrictLimitedRoles;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Etapa 5, paso 5a: los editores solo llegan a sus encargos, y los demás roles siguen igual.

/** Lo que responde el candado de RestrictLimitedRoles a un rol que pide esta dirección (200 = pasa). */
function candado(string $role, string $method, string $path): int
{
    $user = new User(['names' => 'Prueba']);
    $user->setRelation('role', new Role(['description' => $role]));
    $request = Request::create($path, $method);
    $request->setUserResolver(fn () => $user);

    return (new RestrictLimitedRoles())->handle($request, fn () => response('ok'))->getStatusCode();
}

describe('El candado de los editores', function () {
    it('los dos editores llegan a sus encargos, a su sesión y a su perfil', function () {
        foreach (['Editor líder', 'Editor de video'] as $role) {
            foreach ([['GET', '/api/editor/queue'], ['GET', '/api/editor/summary'], ['POST', '/api/editor/queue/5/deliver'],
                ['GET', '/api/user/data'], ['POST', '/api/logout'], ['PUT', '/api/user/7/change/theme']] as [$method, $path]) {
                expect(candado($role, $method, $path))->toBe(200, "{$role} {$method} {$path}");
            }
        }
    });

    it('a todo lo demás, los editores reciben "sin permiso"', function () {
        foreach (['Editor líder', 'Editor de video'] as $role) {
            foreach ([['GET', '/api/orders'], ['GET', '/api/dashboard'], ['GET', '/api/meta/queue'], ['POST', '/api/meta/queue/5/review'],
                ['GET', '/api/users'], ['GET', '/api/products'], ['POST', '/api/assistant/chat'], ['GET', '/api/whatsapp/conversations'],
                ['GET', '/api/logistics/board'], ['GET', '/api/rider/today'], ['PUT', '/api/user/7']] as [$method, $path]) {
                expect(candado($role, $method, $path))->toBe(403, "{$role} {$method} {$path}");
            }
        }
    });

    it('los demás roles siguen igual: los de siempre pasan y el rider y el operador no llegan a los encargos', function () {
        foreach (['Admin', 'Gerente', 'Vendedor', 'Agencia', 'Master'] as $role) {
            expect(candado($role, 'GET', '/api/orders'))->toBe(200, $role);
        }
        expect(candado('Repartidor', 'GET', '/api/rider/today'))->toBe(200)
            ->and(candado('Repartidor', 'GET', '/api/editor/queue'))->toBe(403)
            ->and(candado('Operador logístico', 'GET', '/api/logistics/board'))->toBe(200)
            ->and(candado('Operador logístico', 'GET', '/api/editor/queue'))->toBe(403);
    });
});

describe('Las rutas', function () {
    $routes = fn (string $prefix) => collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), $prefix));

    it('las de los editores piden uno de los dos roles de editor', function () use ($routes) {
        $editor = $routes('api/editor/');
        expect($editor)->toHaveCount(6);
        foreach ($editor as $r) {
            expect($r->gatherMiddleware())->toContain('role:Editor líder,Editor de video')->toContain('limited.roles');
        }
    });

    it('las de la Cola y banca siguen siendo solo del Admin, también las nuevas de asignar y revisar', function () use ($routes) {
        $queue = $routes('api/meta/queue');
        expect($queue->map->uri()->all())->toContain('api/meta/queue/{batch}/editor', 'api/meta/queue/{batch}/review');
        foreach ($queue as $r) {
            expect($r->gatherMiddleware())->toContain('role:Admin');
        }
    });

    it('el rol del Admin no entra a las pantallas de los editores (las suyas son las de Meta Ads)', function () {
        $user = new User(['names' => 'Dueño']);
        $user->setRelation('role', new Role(['description' => 'Admin']));
        $request = Request::create('/api/editor/queue');
        $request->setUserResolver(fn () => $user);
        expect((new EnsureRole())->handle($request, fn () => response('ok'), 'Editor líder', 'Editor de video')->getStatusCode())->toBe(403);
        $user->setRelation('role', new Role(['description' => 'Editor líder']));
        expect((new EnsureRole())->handle($request, fn () => response('ok'), 'Editor líder', 'Editor de video')->getStatusCode())->toBe(200);
    });
});
