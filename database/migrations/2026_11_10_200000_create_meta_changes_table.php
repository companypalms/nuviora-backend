<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.131` (dueño, 2026-11-10: "necesito que cada cambio de Meta Ads tenga una hipótesis que se guarda en un historial… y así el
 * sistema de IA puede aprender automáticamente de lo que funciona y no funciona"): cada cambio que el sistema ve en Meta
 * (presupuesto, pausar o prender, apagar anuncios, batch nuevo, quitar un mínimo), con su hipótesis, los números de antes y
 * de después, y si se cumplió. Tabla nueva: no toca nada de lo que ya existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_changes', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 120)->unique();              // tipo:campaña:ad set:fecha (para no repetirlo)
            $table->string('kind', 16);                                // raise, lower, pause, resume, ads_off, new_batch, min_off
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('campaign_meta_id', 32)->index();
            $table->string('adset_meta_id', 32)->nullable();
            $table->string('city', 80)->nullable();
            $table->date('date');                                      // el día del cambio (hora de Venezuela)
            $table->dateTime('changed_at');
            $table->json('detail')->nullable();                        // de cuánto a cuánto, qué anuncios, qué ad set
            $table->json('mission')->nullable();                       // la misión del análisis que lo pidió, o null (por su cuenta)
            $table->decimal('target_c', 10, 2)->nullable();            // la C y el BE de ese día
            $table->decimal('target_be', 10, 2)->nullable();
            $table->text('hypothesis')->nullable();
            $table->string('criterion', 255)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('result', 16)->default('measuring');        // measuring, met, not_met, unknown, not_measured
            $table->string('result_text', 500)->nullable();
            $table->dateTime('measured_at')->nullable();
            $table->string('note', 500)->nullable();                   // la nota del dueño
            $table->timestamps();
            $table->index(['date', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_changes');
    }
};
