<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\User;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShopController extends Controller
{
    /** Lo que no se devuelve nunca (Shop::$hidden) y que, vacío al editar, no se borra. */
    private const SECRETS = ['shopify_access_token', 'shopify_webhook_secret'];

    /** Días en que abre sola (1 = lunes … 7 = domingo); null o los 7 = todos (dueño, 2026-11-01). */
    private const DAYS_RULE = 'nullable|array|min:1';

    private const DAYS_MESSAGES = ['auto_open_days.min' => 'Marca al menos un día en que la tienda abre sola.'];

    /** Los días ordenados y sin repetir; los 7 se guardan como "todos los días" (null). */
    private static function cleanDays(array $data): array
    {
        if (array_key_exists('auto_open_days', $data) && is_array($data['auto_open_days'])) {
            $days = array_values(array_unique(array_map('intval', $data['auto_open_days'])));
            sort($days);
            $data['auto_open_days'] = count($days) === 7 ? null : $days;
        }

        return $data;
    }

    protected function ensureAdmin()
    {
        $role = Auth::user()->role?->description;
        if ($role !== 'Admin') {
            abort(403, 'No autorizado');
        }
    }

    public function index()
    {
        $shops = Shop::with(['status', 'sellers'])->get();
        return response()->json([
            'status' => true,
            'data' => $shops
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureAdmin();
        $data = $request->validate([
            'name' => 'required|string',
            'shopify_domain' => 'nullable|string',
            'shopify_access_token' => 'nullable|string',
            'shopify_webhook_secret' => 'nullable|string',
            'status_id' => 'nullable|exists:statuses,id',
            'auto_open_at' => 'nullable|date_format:H:i',
            'auto_close_at' => 'nullable|date_format:H:i',
            'auto_schedule_enabled' => 'boolean',
            'auto_open_days' => self::DAYS_RULE,
            'auto_open_days.*' => 'integer|between:1,7',
        ], self::DAYS_MESSAGES);

        $data = self::cleanDays($data);
        $shop = Shop::create($data);
        return response()->json($shop);
    }

    public function update(Request $request, Shop $shop)
    {
        $this->ensureAdmin();
        $data = $request->validate([
            'name' => 'string',
            'shopify_domain' => 'nullable|string',
            'shopify_access_token' => 'nullable|string',
            'shopify_webhook_secret' => 'nullable|string',
            'status_id' => 'nullable|exists:statuses,id',
            'auto_open_at' => 'nullable|date_format:H:i',
            'auto_close_at' => 'nullable|date_format:H:i',
            'auto_schedule_enabled' => 'boolean',
            'auto_open_days' => self::DAYS_RULE,
            'auto_open_days.*' => 'integer|between:1,7',
        ], self::DAYS_MESSAGES);
        $data = self::cleanDays($data);

        // La pantalla ya no recibe el token ni el secreto: si llegan vacíos, se quedan los que están
        foreach (self::SECRETS as $key) {
            if (array_key_exists($key, $data) && trim((string) $data[$key]) === '') {
                unset($data[$key]);
            }
        }

        $shop->update($data);
        return response()->json($shop);
    }

    public function assignSellers(Request $request, Shop $shop)
    {
        $this->ensureAdmin();
        $data = $request->validate([
            'seller_ids' => 'required|array',
            'seller_ids.*' => 'exists:users,id',
            'default_roster_ids' => 'nullable|array',
            'default_roster_ids.*' => 'exists:users,id',
        ]);

        $sellerIds = $data['seller_ids'];
        $defaultRosterIds = $data['default_roster_ids'] ?? [];

        $syncData = [];
        foreach ($sellerIds as $id) {
            $syncData[$id] = ['is_default_roster' => in_array($id, $defaultRosterIds)];
        }

        $shop->sellers()->sync($syncData);
        return response()->json(['message' => 'Vendedores asignados correctamente']);
    }

    public function destroy(Shop $shop)
    {
        $this->ensureAdmin();
        $shop->delete();
        return response()->json(['message' => 'Tienda eliminada']);
    }
}
