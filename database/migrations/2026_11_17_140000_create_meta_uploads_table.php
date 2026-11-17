<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.155` (dueño, 2026-11-17: "que me aparezcan en mis misiones diarias, yo le doy un botón de aprobar y él los suba
 * automáticamente en meta ads"): el registro de cada subida de un batch a una campaña. Guarda lo que se aprobó y cada cosa
 * que se creó en Meta (con su número), para seguir donde se quedó y no crear nada dos veces. No borra nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creative_batch_id')->constrained()->cascadeOnDelete();
            $table->string('campaign_meta_id', 32);                       // la campaña donde va
            $table->foreignId('meta_ad_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('meta_analysis_id')->nullable()->constrained('meta_analyses')->nullOnDelete(); // la misión que la pidió
            $table->string('mission_key', 120)->nullable();
            $table->string('status', 12)->default('queued');               // queued, running, waiting, done, failed
            $table->string('step', 16)->nullable();                        // files, processing, adset, ads, finish
            $table->boolean('paused')->default(false);                     // las primeras 5 se crean en pausa
            $table->json('plan');                                          // lo que se aprobó (UploadPlanner)
            $table->json('created')->nullable();                           // lo creado en Meta, con sus números
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('waiting_since')->nullable();                // desde cuándo espera que Meta procese los videos
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['creative_batch_id', 'campaign_meta_id']);     // un batch, una vez por campaña
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_uploads');
    }
};
