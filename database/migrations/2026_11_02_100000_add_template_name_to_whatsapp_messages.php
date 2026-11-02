<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El nombre de la plantilla de Meta con la que salió un mensaje automático (dueño, 2026-11-02). Antes, la única pista
     * era el texto guardado ("Plantilla: confirmacion_…"), y si la plantilla estaba importada en el sistema se guardaba el
     * texto completo: el sistema nunca reconocía la confirmación y no salió ningún recordatorio en un mes. Solo agrega.
     */
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('template_name', 120)->nullable()->after('message_type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex(['template_name']);
            $table->dropColumn('template_name');
        });
    }
};
