<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Meta\Products\ProductQueue;
use Illuminate\Http\Request;

/**
 * `.154` Meta Ads › Productos por montar (dueño, 2026-11-17: "quiero tener una banca de productos por montar… y el sistema
 * automáticamente se lo vaya pasando al editor líder"). Solo el Administrador. La cola se llena desde el asistente
 * ("Producto nuevo: X") o la ficha (fase "En cola"); aquí se ve cómo va, se ordena o se saca uno. Que salga el siguiente no
 * lleva botón: lo decide el sistema (ProductQueue::release).
 */
class MetaProductQueueController extends Controller
{
    public function index()
    {
        return response()->json(ProductQueue::view());
    }

    /** Subir o bajar uno un lugar. */
    public function move(Request $request, Product $product)
    {
        $data = $request->validate(['dir' => 'required|in:up,down']);
        ProductQueue::move($product->id, $data['dir']);

        return response()->json(ProductQueue::view());
    }

    /** "Sacar de la cola". */
    public function remove(Product $product)
    {
        ProductQueue::remove($product->id);

        return response()->json(ProductQueue::view());
    }
}
