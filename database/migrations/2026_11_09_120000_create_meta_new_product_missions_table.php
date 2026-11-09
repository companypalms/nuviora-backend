<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.118`: la misión de lanzar un producto nuevo: cuándo se abrió, qué producto es y cuándo se terminó cada etapa. El ritmo es
 * de 1 por semana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_new_product_missions', function (Blueprint $table) {
            $table->id();
            $table->string('status', 8)->default('open');
            $table->date('opened_on');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('search_done_at')->nullable();
            $table->timestamp('strategy_done_at')->nullable();
            $table->timestamp('creatives_done_at')->nullable();
            $table->timestamp('landing_done_at')->nullable();
            $table->timestamp('publish_done_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_new_product_missions');
    }
};
