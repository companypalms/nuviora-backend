<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encargos que explican el objetivo (`.63`, pedido del dueño del 2026-11-01): "la IA no debe decir qué creativos hacer
 * exactamente, sino explicarle al editor qué se busca". Lo que buscamos y lo que hay que mejorar, para el editor (la
 * hipótesis queda solo para el dueño). Solo agrega columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_batches', function (Blueprint $table) {
            $table->text('goal')->nullable()->after('hypothesis');     // lo que buscamos
            $table->text('improve')->nullable()->after('keep');        // lo que hay que mejorar
        });
    }

    public function down(): void
    {
        Schema::table('creative_batches', function (Blueprint $table) {
            $table->dropColumn(['goal', 'improve']);
        });
    }
};
