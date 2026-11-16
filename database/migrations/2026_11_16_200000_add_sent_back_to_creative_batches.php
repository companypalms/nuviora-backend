<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.148` (dueño, 2026-11-16: "los videos estaban malos… no era el producto indicado… necesito que yo reenvíe eso al editor
 * líder con el inconveniente… con una nota… y que él a su vez pueda reasignárselo al mismo o a otro editor"): la última vez
 * que el dueño devolvió el batch desde la banca, con su nota, las piezas que están mal y quién lo había hecho.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_batches', function (Blueprint $table) {
            $table->timestamp('sent_back_at')->nullable();
            $table->foreignId('sent_back_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('sent_back_note')->nullable();
            $table->json('sent_back_pieces')->nullable();                // los códigos de las piezas que están mal ("C0199")
            $table->foreignId('sent_back_editor_id')->nullable()->constrained('users')->nullOnDelete(); // quien lo había hecho
        });
    }

    public function down(): void
    {
        Schema::table('creative_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_back_by');
            $table->dropConstrainedForeignId('sent_back_editor_id');
            $table->dropColumn(['sent_back_at', 'sent_back_note', 'sent_back_pieces']);
        });
    }
};
