<?php

namespace App\Http\Controllers;

use App\Models\StorefrontOrder;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontLimit;
use App\Services\Storefront\StorefrontOrders;
use App\Services\Storefront\StorefrontRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * `.164`: lo público de nuestro formulario en la landing de la tienda (sin sesión ni cookies; `routes/tienda.php`). El
 * cargador, la configuración de un producto, el pedido y el embudo. Solo responde a la tienda: el navegador solo deja leer
 * las respuestas desde los dominios que el dueño puso en Ajustes. Si algo de aquí falla, la landing muestra Releasit.
 */
class StorefrontController extends Controller
{
    /** Los pasos del embudo que se cuentan (sin datos personales). */
    private const EVENTS = ['view', 'start', 'submit', 'error', 'fallback', 'whatsapp'];

    private const FIELDS = ['offer', 'size', 'name', 'phone', 'city', 'address', 'reference', 'express'];

    public function __construct(private StorefrontCatalog $catalog)
    {
    }

    /** El cargador que pega el dueño en su landing: el mismo para todos los productos (lo que cambia viene en la config). */
    public function loader(Request $request): Response
    {
        $js = file_get_contents(resource_path('storefront/cargador.js'));
        $base = rtrim($request->getSchemeAndHttpHost(), '/');

        return response("window.__NUVIORA_BASE__=" . json_encode($base) . ";\n" . $js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff', 'Access-Control-Allow-Origin' => '*',
        ]);
    }

    public function config(Request $request, string $shopifyId): Response
    {
        $product = $this->catalog->product($shopifyId);
        // La vista previa de "Formulario de la tienda" (desde el panel) se ve aunque el formulario esté apagado
        $preview = $product !== null && $request->query('prueba') === '1' && $this->panel((string) $request->headers->get('Origin'));
        if (!$preview && !$this->catalog->enabledFor($product)) {
            return $this->json($request, ['enabled' => false], 200);
        }

        return $this->json($request, ['enabled' => true] + $this->catalog->config($product, $request->query('tasa'), $preview ? null : $request->query('precio')));
    }

    public function order(Request $request, StorefrontOrders $orders): Response
    {
        $in = $this->body($request);
        $product = $this->catalog->product((string) ($in['p'] ?? ''));
        if (!$this->catalog->enabledFor($product)) {
            return $this->json($request, ['ok' => false, 'message' => StorefrontOrders::LIMIT_DAY], 404);
        }
        // Un robot: llena el campo escondido o lo manda antes de que una persona pueda llenarlo
        $ts = (int) ($in['ts'] ?? 0);
        if (!empty($in['hp']) || !hash_equals(StorefrontRules::sign($ts, (string) config('app.key')), (string) ($in['sig'] ?? ''))
            || now()->timestamp - $ts < StorefrontRules::MIN_SECONDS || now()->timestamp - $ts > 86400) {
            return $this->json($request, ['ok' => false, 'message' => StorefrontOrders::LIMIT_DAY], 422);
        }
        $cities = array_column(StorefrontCatalog::codCities((int) $product->id), 'id');
        $v = Validator::make($in, [
            'name' => 'required|string|min:3|max:80',
            'phone' => ['required', 'string', 'max:30', fn ($a, $value, $fail) => StorefrontRules::phone($value) === null ? $fail('phone') : null],
            'city' => ['required', 'integer', fn ($a, $value, $fail) => in_array((int) $value, $cities, true) ? null : $fail('city')],
            'address' => 'required|string|min:5|max:250',
            'reference' => 'nullable|string|max:250',
            'offer' => 'nullable|integer',
            'variant' => 'nullable|integer',
            'express' => 'nullable|boolean',
        ]);
        if ($v->fails()) {
            return $this->json($request, ['ok' => false, 'fields' => array_keys($v->errors()->toArray())], 422);
        }
        $hasVariants = \App\Models\ProductVariant::where('product_id', $product->id)->where('is_active', true)->exists();
        if ($hasVariants && empty($in['variant'])) {
            return $this->json($request, ['ok' => false, 'fields' => ['size']], 422);
        }
        try {
            $r = $orders->place($product, [
                'offer' => !empty($in['offer']) ? (int) $in['offer'] : null, 'variant' => !empty($in['variant']) ? (int) $in['variant'] : null,
                'name' => trim((string) $in['name']), 'phone' => (string) $in['phone'], 'city' => (int) $in['city'], 'address' => trim((string) $in['address']),
                'reference' => trim((string) ($in['reference'] ?? '')) ?: null, 'express' => (bool) ($in['express'] ?? false), 'rate' => $in['rate'] ?? null,
                'visitor' => $in['visitor'] ?? null, 'landing' => $in['landing'] ?? null, 'referrer' => $in['referrer'] ?? null, 'page' => $in['page'] ?? null,
                'fbp' => $in['fbp'] ?? null, 'fbc' => $in['fbc'] ?? null,
            ], ['ip' => $request->ip(), 'ua' => $request->userAgent()]);
        } catch (StorefrontLimit $e) {
            return $this->json($request, ['ok' => false, 'message' => $e->getMessage(), 'limit' => true], 429);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->json($request, ['ok' => false, 'fields' => ['offer']], 422);
        }
        $front = $r['storefront'];
        $wa = StorefrontOrders::whatsapp($r['order'], StorefrontCatalog::settings());

        return $this->json($request, [
            'ok' => true, 'duplicate' => $r['duplicate'], 'order' => $r['order']->name, 'id' => $front->id,
            'whatsapp' => $wa['link'],
            'event' => ['id' => $front->event_id, 'value' => round((float) $front->total_usd, 2), 'currency' => 'USD', 'num_items' => (int) $front->units],
        ]);
    }

    /** El embudo del formulario: cuántas veces se vio, se empezó, se tocó cada campo, se mandó, falló o pasó a WhatsApp. */
    public function events(Request $request): Response
    {
        $in = $this->body($request);
        $product = $this->catalog->product((string) ($in['p'] ?? ''));
        if ($product === null) {
            return response('', 204);
        }
        $allowed = [...self::EVENTS, ...array_map(fn ($f) => "field:{$f}", self::FIELDS)];
        $kinds = array_values(array_unique(array_intersect(array_map('strval', (array) ($in['kinds'] ?? [])), $allowed)));
        $today = now()->toDateString();
        foreach (array_slice($kinds, 0, 12) as $kind) {
            DB::table('storefront_events')->upsert([['product_id' => $product->id, 'date' => $today, 'kind' => $kind, 'count' => 1]],
                ['product_id', 'date', 'kind'], ['count' => DB::raw('count + 1')]);
        }
        if (in_array('whatsapp', $kinds, true) && !empty($in['order'])) {
            StorefrontOrder::whereKey((int) $in['order'])->where('product_id', $product->id)->whereNull('whatsapp_opened_at')->update(['whatsapp_opened_at' => now()]);
        }

        return $this->json($request, ['ok' => true]);
    }

    /** El formulario manda texto (así el navegador no pregunta antes por CORS): es un JSON. */
    private function body(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);

        return is_array($data) ? $data : $request->all();
    }

    /** Solo la tienda (los dominios de Ajustes) puede leer la respuesta. */
    private function json(Request $request, array $data, int $status = 200): Response
    {
        $headers = ['Cache-Control' => 'no-store', 'Vary' => 'Origin'];
        $origin = (string) $request->headers->get('Origin');
        if ($origin !== '' && $this->allowed($origin)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
        }

        return response()->json($data, $status, $headers);
    }

    private function allowed(string $origin): bool
    {
        $clean = StorefrontRules::origin($origin);
        if ($clean === null) {
            return false;
        }
        $list = StorefrontCatalog::settings()['allowed_origins'];
        if (app()->environment('local')) {
            $list = [...$list, 'http://127.0.0.1:8090', 'http://localhost:8090'];
        }

        return in_array($clean, $list, true) || $this->panel($origin);
    }

    /** El panel de Nuviora (la vista previa del formulario en "Formulario de la tienda"). */
    private function panel(string $origin): bool
    {
        return $origin !== '' && in_array(rtrim($origin, '/'), array_map(fn ($o) => rtrim((string) $o, '/'), (array) config('cors.allowed_origins', [])), true);
    }
}
