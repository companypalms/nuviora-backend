<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\RestrictLimitedRoles;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Etapa 5, paso 6b: el editor de landings solo llega a sus encargos de landing, y los demás roles siguen igual.

/** Lo que responde el candado de RestrictLimitedRoles a un rol que pide esta dirección (200 = pasa). */
function candadoLanding(string $role, string $method, string $path): int
{
    $user = new User(['names' => 'Prueba']);
    $user->setRelation('role', new Role(['description' => $role]));
    $request = Request::create($path, $method);
    $request->setUserResolver(fn () => $user);

    return (new RestrictLimitedRoles())->handle($request, fn () => response('ok'))->getStatusCode();
}

describe('El candado del editor de landings', function () {
    it('llega a sus encargos de landing, a su sesión y a su perfil', function () {
        foreach ([['GET', '/api/landing-editor/tasks'], ['GET', '/api/landing-editor/summary'], ['POST', '/api/landing-editor/tasks/5/deliver'],
            ['POST', '/api/landing-editor/tasks/5/publish'], ['GET', '/api/landing-editor/results'], ['GET', '/api/user/data'], ['POST', '/api/logout'], ['PUT', '/api/user/7/change/theme']] as [$method, $path]) {
            expect(candadoLanding('Editor de landings', $method, $path))->toBe(200, "{$method} {$path}");
        }
    });

    it('a todo lo demás recibe "sin permiso": ni Meta Ads, ni las landings del dueño, ni los encargos de video', function () {
        foreach ([['GET', '/api/meta/landings'], ['GET', '/api/meta/landing-tasks'], ['POST', '/api/meta/landing-tasks/5/review'], ['GET', '/api/editor/queue'],
            ['GET', '/api/orders'], ['GET', '/api/dashboard'], ['GET', '/api/users'], ['POST', '/api/assistant/chat'], ['PUT', '/api/user/7']] as [$method, $path]) {
            expect(candadoLanding('Editor de landings', $method, $path))->toBe(403, "{$method} {$path}");
        }
    });

    it('los editores de video no llegan a los encargos de landing, y los demás roles siguen igual', function () {
        expect(candadoLanding('Editor de video', 'GET', '/api/landing-editor/tasks'))->toBe(403)
            ->and(candadoLanding('Editor líder', 'GET', '/api/editor/queue'))->toBe(200)
            ->and(candadoLanding('Admin', 'GET', '/api/meta/landing-tasks'))->toBe(200)
            ->and(candadoLanding('Vendedor', 'GET', '/api/orders'))->toBe(200)
            ->and(candadoLanding('Repartidor', 'GET', '/api/landing-editor/tasks'))->toBe(403);
    });
});

describe('Las rutas de los encargos de landing', function () {
    $routes = fn (string $prefix) => collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), $prefix));

    it('las del editor piden su rol; las del dueño, el Admin', function () use ($routes) {
        $editor = $routes('api/landing-editor/');
        expect($editor)->toHaveCount(6); // con /landing-editor/results del paso 6c
        foreach ($editor as $r) {
            expect($r->gatherMiddleware())->toContain('role:Editor de landings')->toContain('limited.roles');
        }
        $owner = $routes('api/meta/landing');
        expect($owner)->toHaveCount(13); // con /meta/landing-tasks/propose del paso 6c
        foreach ($owner as $r) {
            expect($r->gatherMiddleware())->toContain('role:Admin');
        }
    });

    it('el Admin no entra a la pantalla del editor de landings (la suya es Meta Ads › Landings)', function () {
        $user = new User(['names' => 'Dueño']);
        $user->setRelation('role', new Role(['description' => 'Admin']));
        $request = Request::create('/api/landing-editor/tasks');
        $request->setUserResolver(fn () => $user);
        expect((new EnsureRole())->handle($request, fn () => response('ok'), 'Editor de landings')->getStatusCode())->toBe(403);
        $user->setRelation('role', new Role(['description' => 'Editor de landings']));
        expect((new EnsureRole())->handle($request, fn () => response('ok'), 'Editor de landings')->getStatusCode())->toBe(200);
    });
});
