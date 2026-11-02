<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `.81` (dueño, 2026-11-02): "el sistema siempre debe saber qué hipótesis se está probando en cada creativo, para que
     * aprendamos de lo que funciona y lo que no". Cada pieza guarda qué prueba, el formato que prueba, su hipótesis, por qué
     * se eligió ese formato y, al terminar el test, si ganó o perdió. Solo agrega.
     */
    public function up(): void
    {
        Schema::table('creative_batch_pieces', function (Blueprint $table) {
            $table->string('test', 24)->nullable()->after('format');         // winner_hook, hook, middle, format… (PieceRules::TESTS)
            $table->string('style', 24)->nullable()->after('test');          // el formato que prueba: imagen, ugc, testimonio…
            $table->text('hypothesis')->nullable()->after('style');
            $table->string('reason', 191)->nullable()->after('hypothesis');  // por qué ese formato (para el dueño)
            $table->string('result', 12)->nullable()->after('hook');         // Ganó, Perdió o Empate, al terminar el test
            $table->timestamp('result_at')->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('creative_batch_pieces', function (Blueprint $table) {
            $table->dropColumn(['test', 'style', 'hypothesis', 'reason', 'result', 'result_at']);
        });
    }
};
