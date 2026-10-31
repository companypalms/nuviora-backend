<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que dicen los videos de los creativos ganadores (`.55`, 2026-10-31): el texto, el hook (lo de los primeros 3
 * segundos) y, si no se pudo, por qué. Un video, una fila (el mismo video puede estar en varios anuncios).
 * Solo agrega una tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_video_transcripts', function (Blueprint $table) {
            $table->id();
            $table->string('video_id', 40)->unique();
            $table->foreignId('meta_ad_account_id')->nullable()->constrained('meta_ad_accounts')->nullOnDelete();
            $table->string('status', 12);                 // done, no_access, too_big, failed
            $table->text('hook')->nullable();             // lo que se dice en los primeros 3 segundos
            $table->longText('text')->nullable();         // todo lo que se dice
            $table->json('segments')->nullable();         // [{start, end, text}] si OpenAI los da
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('transcribed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_video_transcripts');
    }
};
