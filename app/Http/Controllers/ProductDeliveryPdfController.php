<?php

namespace App\Http\Controllers;

use App\Models\DeliveryPdfSend;
use App\Models\Product;
use App\Services\WhatsApp\DeliveryPdfSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * `.106`: el PDF que recibe el cliente del producto al entregarle (Inventario › producto › Editar, solo Admin/Master).
 * Subirlo, cambiar el texto que lo acompaña y quitarlo (quitarlo apaga el envío al instante). Ver DeliveryPdfSender.
 */
class ProductDeliveryPdfController extends Controller
{
    public function show($id)
    {
        return response()->json($this->payload(Product::findOrFail($id)));
    }

    public function store(Request $request, $id)
    {
        $product = Product::findOrFail($id);
        $request->validate(['file' => 'required|file|mimes:pdf|max:20480']);

        $file = $request->file('file');
        $old = $product->delivery_pdf_path;
        $name = trim(preg_replace('/[\\\\\/:*?"<>|]+/', ' ', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))) ?: 'Guía';
        $product->forceFill([
            'delivery_pdf_path' => $file->store('products/pdfs', 'public'),
            'delivery_pdf_name' => mb_substr($name, 0, 120) . '.pdf',
            // Cambiar el PDF no cambia desde cuándo se manda; subir uno nuevo después de quitarlo, sí
            'delivery_pdf_since' => $product->delivery_pdf_since ?? now(),
        ])->saveQuietly();
        if ($old && $old !== $product->delivery_pdf_path) {
            Storage::disk('public')->delete($old);
        }

        return response()->json($this->payload($product));
    }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);
        $data = $request->validate(['caption' => 'nullable|string|max:900']);
        $product->forceFill(['delivery_pdf_caption' => trim((string) ($data['caption'] ?? '')) ?: null])->saveQuietly();

        return response()->json($this->payload($product));
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        if ($product->delivery_pdf_path) {
            Storage::disk('public')->delete($product->delivery_pdf_path);
        }
        $product->forceFill(['delivery_pdf_path' => null, 'delivery_pdf_name' => null, 'delivery_pdf_since' => null])->saveQuietly();

        return response()->json($this->payload($product));
    }

    private function payload(Product $product): array
    {
        $sent = DeliveryPdfSend::where('product_id', $product->id)->where('status', DeliveryPdfSend::SENT);

        return [
            'name' => $product->delivery_pdf_name,
            'url' => $product->delivery_pdf_path ? Storage::disk('public')->url($product->delivery_pdf_path) : null,
            'since' => $product->delivery_pdf_since?->toDateTimeString(),
            'caption' => $product->delivery_pdf_caption,
            'default_caption' => DeliveryPdfSender::DEFAULT_CAPTION,
            'sent_count' => (clone $sent)->count(),
            'last_sent_at' => (clone $sent)->max('sent_at'),
        ];
    }
}
