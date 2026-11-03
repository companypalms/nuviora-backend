<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.94` (dueño, entrevista del rol de editor): al asignar, el líder puede sugerir de qué referencia partir. El editor la ve
 * en su encargo y ya viene elegida en "¿De dónde salió?" al entregar (la puede cambiar). Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_batches', function (Blueprint $table) {
            $table->foreignId('suggested_reference_id')->nullable()->after('editor_id')->constrained('creative_references')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('creative_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suggested_reference_id');
        });
    }
};
