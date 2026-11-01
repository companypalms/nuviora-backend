<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.63` (dueño, 2026-11-01): "el editor tiene que saber algunas cosas sobre la hipótesis que se está testeando para que
 * tenga noción y contexto". Qué estamos probando, contado para el editor (la idea y por qué creemos que puede funcionar,
 * en términos del cliente y del video, sin números ni el proceso del dueño). La hipótesis sigue solo para el dueño.
 * Solo agrega una columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_batches', function (Blueprint $table) {
            $table->text('test_idea')->nullable()->after('goal');
        });
    }

    public function down(): void
    {
        Schema::table('creative_batches', function (Blueprint $table) {
            $table->dropColumn('test_idea');
        });
    }
};
