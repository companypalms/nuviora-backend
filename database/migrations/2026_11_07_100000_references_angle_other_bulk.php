<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `.97` (dueño, 2026-11-04): el ángulo de una referencia es obligatorio, con "Otro ángulo" y una frase de cuál es
 * (`angle_other`); y "Subir varias", donde el link de cada una es opcional y el tipo y el "por qué" también (por eso
 * aceptan vacío). Las referencias que ya están no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_references', function (Blueprint $table) {
            $table->string('angle_other', 160)->nullable()->after('angle_code');
            $table->string('link', 1000)->nullable()->change();
            $table->string('link_key', 64)->nullable()->change();
            $table->string('type', 20)->nullable()->change();
            $table->string('why', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('creative_references')->whereNull('link')->update(['link' => '', 'link_key' => '']);
        DB::table('creative_references')->whereNull('type')->update(['type' => 'other']);
        DB::table('creative_references')->whereNull('why')->update(['why' => '']);
        Schema::table('creative_references', function (Blueprint $table) {
            $table->dropColumn('angle_other');
            $table->string('link', 1000)->nullable(false)->change();
            $table->string('link_key', 64)->nullable(false)->change();
            $table->string('type', 20)->nullable(false)->change();
            $table->string('why', 500)->nullable(false)->change();
        });
    }
};
