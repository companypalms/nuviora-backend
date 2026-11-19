<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Shop;
use App\Models\StorefrontDesign;
use App\Models\StorefrontOrder;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontKpis;
use App\Services\Storefront\StorefrontRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * `.164` "Formulario de la tienda" (solo el Admin): prenderlo, la tienda y el producto, sus ofertas y su diseño, el píxel y
 * la API de conversiones (la clave solo se escribe: nunca vuelve a la pantalla), el WhatsApp, el código para pegar en la
 * landing, los KPIs y los pedidos que entraron por ahí.
 */
class StoreFormController extends Controller
{
    public function index(Request $request, StorefrontKpis $kpis)
    {
        $s = StorefrontCatalog::settings();
        $ids = $s['product_ids'];
        $products = Product::whereNotNull('product_id')->orderBy('showable_name')->orderBy('title')->get(['id', 'title', 'showable_name', 'product_id', 'price', 'image', 'variant_id']);

        return response()->json([
            'settings' => array_diff_key($s, ['capi_token' => 1]) + ['capi_set' => $s['capi_token'] !== null],
            'shops' => Shop::orderBy('name')->get(['id', 'name', 'shopify_domain']),
            'products' => $products->map(fn ($p) => ['id' => $p->id, 'name' => $p->showable_name ?: $p->title, 'shopify_id' => (string) $p->product_id,
                'price' => (float) $p->price, 'image' => $p->image])->values(),
            'selected' => $products->whereIn('id', $ids)->map(fn ($p) => $this->selected($p))->values(),
            'orders' => $this->orders($ids),
            'kpis' => $kpis->current($ids),
            'base_url' => rtrim($request->getSchemeAndHttpHost(), '/'),
            'texts' => StorefrontRules::TEXTS,
            'colors' => StorefrontRules::COLORS,
            'formats' => StorefrontRules::CONTENT_FORMATS,
            'rate' => (float) \App\Models\Setting::get('rate_binance_usd', 0),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'shop_id' => 'nullable|integer|exists:shops,id',
            'product_ids' => 'present|array|max:20',
            'product_ids.*' => 'integer|exists:products,id',
            'express_usd' => 'required|numeric|min:0|max:50',
            'whatsapp_number' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{10,18}$/'],
            'whatsapp_message' => 'required|string|max:1000',
            'allowed_origins' => 'present|array|max:10',
            'allowed_origins.*' => 'string|max:120',
            'releasit_selector' => 'nullable|string|max:500',
            'pixel_id' => ['nullable', 'string', 'regex:/^\d{6,20}$/'],
            'capi_token' => 'nullable|string|max:600',
            'capi_token_clear' => 'sometimes|boolean',
            'capi_test_code' => 'nullable|string|max:40',
            'content_id_format' => 'required|in:' . implode(',', array_keys(StorefrontRules::CONTENT_FORMATS)),
            'content_region' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'send_initiate' => 'required|boolean',
            'send_pageview' => 'required|boolean',
            'daily_max' => 'required|integer|min:10|max:5000',
        ]);
        abort_if($data['enabled'] && empty($data['shop_id']), 422, 'Elige tu tienda antes de prender el formulario.');
        abort_if($data['enabled'] && $data['product_ids'] === [], 422, 'Elige el producto donde va el formulario.');
        foreach ($data['allowed_origins'] as $o) {
            abort_if(StorefrontRules::origin($o) === null, 422, "«{$o}» no es una dirección de tu tienda (empieza con https://).");
        }
        $noShopify = Product::whereIn('id', $data['product_ids'])->whereNull('product_id')->exists();
        abort_if($noShopify, 422, 'Ese producto no está unido a Shopify todavía: pásale su link a tu asistente.');
        $saved = StorefrontCatalog::saveSettings($data + ['releasit_selector' => '', 'pixel_id' => '', 'capi_test_code' => '']);
        Cache::forget('storefront:kpis:' . implode(',', $saved['product_ids']));

        return response()->json(['message' => $saved['enabled'] ? 'Guardado. El formulario está prendido.' : 'Guardado. El formulario está apagado.',
            'settings' => array_diff_key($saved, ['capi_token' => 1]) + ['capi_set' => $saved['capi_token'] !== null]]);
    }

    /** Las ofertas del producto, como las de Releasit (se reemplazan todas). */
    public function saveOffers(Request $request, Product $product)
    {
        $data = $request->validate([
            'offers' => 'present|array|max:6',
            'offers.*.units' => 'required|integer|min:1|max:10',
            'offers.*.discount_pct' => 'required|numeric|min:0|max:90',
            'offers.*.title' => 'nullable|string|max:120',
            'offers.*.badge' => 'nullable|string|max:60',
            'offers.*.is_default' => 'required|boolean',
            'offers.*.is_active' => 'required|boolean',
        ]);
        abort_if(count(array_filter($data['offers'], fn ($o) => $o['is_default'])) > 1, 422, 'Solo una oferta puede salir marcada.');
        DB::transaction(function () use ($product, $data) {
            ProductOffer::where('product_id', $product->id)->delete();
            foreach (array_values($data['offers']) as $i => $o) {
                ProductOffer::create(['product_id' => $product->id, 'position' => $i] + $o);
            }
        });
        StorefrontCatalog::refresh();

        return response()->json(['message' => 'Ofertas guardadas.', 'product' => $this->selected($product->fresh())]);
    }

    /** El diseño del formulario en la landing de este producto. */
    public function saveDesign(Request $request, Product $product)
    {
        $data = $request->validate([
            'texts' => 'present|array',
            'texts.*' => 'nullable|string|max:300',
            'colors' => 'present|array',
            'colors.*' => ['nullable', 'string', 'regex:/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/'],
            'font' => ['nullable', 'string', 'regex:/^[A-Za-z0-9 ]{2,60}$/'],
            'radius' => 'nullable|integer|min:0|max:30',
            'custom_css' => 'nullable|string|max:20000',
        ]);
        $texts = array_filter(array_intersect_key($data['texts'], StorefrontRules::TEXTS), fn ($v) => is_string($v) && trim($v) !== '' );
        $texts = array_filter($texts, fn ($v, $k) => $v !== StorefrontRules::TEXTS[$k], ARRAY_FILTER_USE_BOTH);
        $colors = array_filter(array_intersect_key($data['colors'], StorefrontRules::COLORS), fn ($v, $k) => $v && strtoupper($v) !== StorefrontRules::COLORS[$k], ARRAY_FILTER_USE_BOTH);
        StorefrontDesign::updateOrCreate(['product_id' => $product->id], ['texts' => $texts ?: null, 'colors' => $colors ?: null,
            'font' => $data['font'] ?? null, 'radius' => $data['radius'] ?? null, 'custom_css' => $data['custom_css'] ?? null]);
        StorefrontCatalog::refresh();

        return response()->json(['message' => 'Diseño guardado.', 'product' => $this->selected($product->fresh())]);
    }

    /** Un producto elegido: sus ofertas, su diseño, dónde se paga al recibir y si el precio de su página cuadra. */
    private function selected(Product $p): array
    {
        $design = StorefrontDesign::where('product_id', $p->id)->first();
        $page = Cache::get("storefront:page_price:{$p->id}");
        $warnings = array_values(array_filter([
            (float) $p->price <= 0 ? 'No tiene precio en el sistema: ponle el precio para que el formulario pueda calcular el total.' : null,
            $page !== null && abs((float) $page - (float) $p->price) >= 0.01
                ? 'Tu página dice $' . number_format((float) $page, 2, ',', '.') . ' y el sistema $' . number_format((float) $p->price, 2, ',', '.') . ': el formulario cobra el del sistema. Corrige el que esté mal.' : null,
            !$p->image ? 'No tiene foto en el sistema: las ofertas salen sin foto.' : null,
        ]));

        return ['id' => $p->id, 'name' => $p->showable_name ?: $p->title, 'shopify_id' => (string) $p->product_id, 'price' => (float) $p->price, 'image' => $p->image,
            'page_price' => $page, 'warnings' => $warnings,
            'offers' => ProductOffer::where('product_id', $p->id)->orderBy('position')->orderBy('id')->get(['id', 'units', 'discount_pct', 'title', 'badge', 'is_default', 'is_active']),
            'design' => ['texts' => (object) ($design?->texts ?? []), 'colors' => (object) ($design?->colors ?? []), 'font' => $design?->font, 'radius' => $design?->radius,
                'custom_css' => $design?->custom_css],
            'cities' => StorefrontCatalog::codCities($p->id)];
    }

    /** Los últimos 30 pedidos del formulario. */
    private function orders(array $ids): array
    {
        return StorefrontOrder::with(['order:id,name,status_id,created_at', 'order.status:id,description', 'product:id,title,showable_name'])
            ->whereIn('product_id', $ids ?: [0])->whereNotNull('order_id')->latest('id')->limit(30)->get()
            ->map(fn ($f) => ['id' => $f->id, 'order_id' => $f->order_id, 'name' => $f->order?->name, 'at' => $f->created_at?->toDateTimeString(),
                'product' => $f->product?->showable_name ?: $f->product?->title, 'units' => $f->units, 'total_bs' => $f->total_bs, 'total_usd' => $f->total_usd,
                'express' => $f->express_fee !== null, 'city' => \App\Models\City::whereKey($f->city_id)->value('name'), 'status' => $f->order?->status?->description,
                'capi' => $f->capi_status, 'whatsapp' => $f->whatsapp_opened_at !== null])->all();
    }
}
