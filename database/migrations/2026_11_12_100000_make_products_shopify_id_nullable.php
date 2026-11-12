<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `.138` (dueño, 2026-11-12: la ficha de un producto nuevo sin link de Shopify fallaba "falló el sistema" una y otra vez): el
 * número de Shopify (`products.product_id`, único) era 0 en los productos sin link, y el 0 no se puede repetir: el segundo
 * producto sin link chocaba con el primero. Ahora un producto sin link queda sin número (null; vacíos puede haber muchos) y
 * los que tenían 0 pasan a null. Al pasarle el link se guarda su número como siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->change(); // el índice único se queda
        });
        DB::table('products')->where('product_id', 0)->update(['product_id' => null]);
    }

    public function down(): void
    {
        // Solo se puede volver si queda a lo sumo uno sin link (el 0 no se repite); si no, la columna se queda como está
        if (DB::table('products')->whereNull('product_id')->count() > 1) {
            return;
        }
        DB::table('products')->whereNull('product_id')->update(['product_id' => 0]);
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
        });
    }
};
