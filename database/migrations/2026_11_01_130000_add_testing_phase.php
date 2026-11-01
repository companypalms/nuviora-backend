<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.65` (dueño, 2026-11-01): la fase de testeo. "En testeo siempre probamos solamente 3 batch, 1 por cada ángulo
 * principal" (con 2 ángulos: 2 del más fuerte y 1 del otro; con 1 ángulo: 2 batches), con conceptos o formatos distintos
 * cuando se repite el ángulo. Después se itera lo que ganó y se para lo que perdió.
 * - product_profiles: la fase (testing / iterate), la ronda de testeo y el resultado de cada ronda terminada;
 * - ad_angles: el orden en la ficha (del más fuerte al más débil; vacío = no está en la ficha) y su buyer persona;
 * - creative_batches: de qué ronda de testeo es (vacío = un encargo normal).
 * Solo agrega columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_profiles', function (Blueprint $table) {
            $table->string('phase', 12)->default('iterate')->after('photos_url');   // testing (En testeo) o iterate (Iterar lo que funciona)
            $table->unsignedTinyInteger('testing_round')->default(0)->after('phase'); // la ronda de testeo actual (0 = nunca se testeó)
            $table->timestamp('phase_at')->nullable()->after('testing_round');
            $table->json('testing_results')->nullable()->after('phase_at');        // las rondas terminadas: ganó, perdió, empate
        });
        Schema::table('ad_angles', function (Blueprint $table) {
            $table->unsignedTinyInteger('position')->nullable()->after('hypothesis'); // el orden en la ficha (1 = el más fuerte)
            $table->foreignId('persona_id')->nullable()->after('position')->constrained('product_personas')->nullOnDelete();
        });
        Schema::table('creative_batches', function (Blueprint $table) {
            $table->unsignedTinyInteger('testing_round')->nullable()->after('persona_id');
        });
    }

    public function down(): void
    {
        Schema::table('creative_batches', function (Blueprint $table) {
            $table->dropColumn('testing_round');
        });
        Schema::table('ad_angles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('persona_id');
            $table->dropColumn('position');
        });
        Schema::table('product_profiles', function (Blueprint $table) {
            $table->dropColumn(['phase', 'testing_round', 'phase_at', 'testing_results']);
        });
    }
};
