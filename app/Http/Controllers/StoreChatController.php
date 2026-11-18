<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StoreChatConversation;
use App\Services\StoreChat\StoreChat;
use App\Services\StoreChat\StoreChatReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * `.158` "Chat de la tienda" (solo el Admin): los ajustes, la página y el chat de prueba del dueño. El chat de los
 * clientes en la tienda llega con la `.159`.
 */
class StoreChatController extends Controller
{
    public function index(StoreChatReport $report)
    {
        return response()->json($report->overview(CarbonImmutable::now()));
    }

    /** Prender o apagar, en qué productos (solo los que tienen ficha) y los topes. */
    public function saveSettings(Request $request, StoreChatReport $report)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'product_ids' => 'present|array|max:200',
            'product_ids.*' => 'integer',
            'daily_answers' => 'required|integer|min:10|max:5000',
            'per_visitor' => 'required|integer|min:3|max:100',
        ]);
        $withFicha = array_column($report->products(CarbonImmutable::now(), []), 'id');
        $missing = array_diff(array_map('intval', $data['product_ids']), $withFicha);
        abort_if($missing !== [], 422, 'Solo los productos con ficha pueden tener chat.');
        $saved = StoreChat::saveSettings($data);

        return response()->json(['message' => $saved['enabled'] ? 'Guardado. El chat está prendido.' : 'Guardado. El chat está apagado.', 'settings' => $saved]);
    }

    /** El dueño prueba el chat como si fuera un cliente (queda marcado "prueba"). */
    public function test(Request $request, StoreChat $chat)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'question' => 'required|string|max:2000',
            'visitor' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,40}$/'],
        ]);

        return response()->json($chat->ask(Product::findOrFail($data['product_id']), $data['visitor'], $data['question'],
            StoreChatConversation::TEST, null, CarbonImmutable::now()));
    }
}
