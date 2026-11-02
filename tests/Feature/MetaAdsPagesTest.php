<?php

use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaHierarchySync;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// 2026-11-02: desde el 31/10 Meta respondía "Please reduce the amount of data you're asking for" a la lista de anuncios
// (con su texto y su landing, de a 200) en todas las cuentas, y los anuncios se quedaron congelados. .77: de a 50, en
// Solobrillo Meta cortaba la conexión ("Connection reset by peer"). Sin llamar a Meta.

const META_TOO_MUCH = "Please reduce the amount of data you're asking for, then retry your request";

/** La lista de anuncios de la cuenta como la pide la sincronización (fetch es privado). */
function pedirAnuncios(): array
{
    $sync = new MetaHierarchySync();
    $fields = 'id,name,creative{id,body,object_story_spec}';
    $basic = 'id,name';

    return (fn () => ['rows' => $this->fetch(new MetaClient('token-de-prueba'), 'act_1/ads', $fields, $basic, MetaHierarchySync::AD_PAGE_SIZES),
        'full' => $this->full])->call($sync);
}

/**
 * Meta de prueba: acepta como mucho $max por página con todos los campos; con los básicos, cualquier página. Con $corta,
 * en vez de "demasiados datos" corta la conexión.
 */
function metaQueAceptaHasta(?int $max, array &$pedidos, bool $corta = false): void
{
    Http::fake(function (Request $request) use ($max, &$pedidos, $corta) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
        $pedidos[] = ['limit' => (int) $q['limit'], 'fields' => $q['fields']];
        $full = str_contains($q['fields'], 'object_story_spec');
        if ($full && ($max === null || (int) $q['limit'] > $max)) {
            return $corta ? Http::failedConnection('cURL error 56: Recv failure: Connection reset by peer')($request)
                : Http::response(['error' => ['message' => META_TOO_MUCH, 'code' => 1]], 500);
        }

        return Http::response(['data' => [['id' => '1', 'name' => 'CRE001-COM-GINE-BATCH003'], ['id' => '2', 'name' => 'C005 FASP']]]);
    });
}

describe('Si Meta dice que son demasiados datos', function () {
    it('pide los anuncios en páginas más chicas, con todos sus datos', function () {
        $pedidos = [];
        metaQueAceptaHasta(25, $pedidos);
        $r = pedirAnuncios();

        expect(array_column($r['rows'], 'id'))->toBe(['1', '2'])
            ->and($r['full'])->toBeTrue()
            ->and(array_column($pedidos, 'limit'))->toBe([50, 25]);
    });

    it('si ni de a 25 alcanza, pide solo lo básico y la lista llega igual', function () {
        $pedidos = [];
        metaQueAceptaHasta(null, $pedidos);
        $r = pedirAnuncios();

        expect(array_column($r['rows'], 'id'))->toBe(['1', '2'])
            ->and($r['full'])->toBeFalse() // no se pisan el texto y la landing que ya estaban guardados
            ->and(array_column($pedidos, 'limit'))->toBe([50, 25, 200])
            ->and(end($pedidos)['fields'])->toBe('id,name');
    });

    it('sin problemas, una sola llamada, directo de a 50', function () {
        $pedidos = [];
        metaQueAceptaHasta(200, $pedidos);
        $r = pedirAnuncios();

        expect(array_column($r['rows'], 'id'))->toBe(['1', '2'])->and($r['full'])->toBeTrue()
            ->and(array_column($pedidos, 'limit'))->toBe([50]);
    });

    it('si Meta corta la conexión, pide lo básico (liviano) y la lista llega igual', function () {
        $pedidos = [];
        metaQueAceptaHasta(null, $pedidos, corta: true);
        $r = pedirAnuncios();

        expect(array_column($r['rows'], 'id'))->toBe(['1', '2'])
            ->and($r['full'])->toBeFalse()
            ->and(array_column($pedidos, 'limit'))->toBe([50, 200])
            ->and(end($pedidos)['fields'])->toBe('id,name');
    });

    it('las campañas y los ad sets se siguen pidiendo de a 200', function () {
        $pedidos = [];
        metaQueAceptaHasta(200, $pedidos);
        (fn () => $this->fetch(new MetaClient('token-de-prueba'), 'act_1/adsets', 'id,name,creative{object_story_spec}'))->call(new MetaHierarchySync());

        expect(array_column($pedidos, 'limit'))->toBe([200]);
    });

    it('otros errores de Meta no se esconden', function () {
        Http::fake(fn () => Http::response(['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]], 400));

        expect(fn () => pedirAnuncios())->toThrow(\App\Services\Meta\MetaApiException::class);
    });
});

describe('Los anuncios que no llegaron', function () {
    $activo = ['id' => '10', 'effective_status' => 'ACTIVE'];
    $pausado = ['id' => '11', 'effective_status' => 'PAUSED'];

    it('se anotan como "ya no están" cuando llegó la lista', function () use ($activo) {
        expect(MetaHierarchySync::marksMissingAds(['1', '2'], [$activo]))->toBeTrue();
    });

    it('una lista vacía con ad sets activos es un error de Meta: no se anota ninguno', function () use ($activo, $pausado) {
        expect(MetaHierarchySync::marksMissingAds([], [$pausado, $activo]))->toBeFalse();
    });

    it('una cuenta sin ad sets activos sí puede quedarse sin anuncios', function () use ($pausado) {
        expect(MetaHierarchySync::marksMissingAds([], [$pausado]))->toBeTrue()
            ->and(MetaHierarchySync::marksMissingAds([], []))->toBeTrue();
    });
});
