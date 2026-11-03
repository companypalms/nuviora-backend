<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// .96: lo que los editores le preguntan a su ayuda "¿Dudas?" (Services\Help\EditorHelp). Sirve para el tope del día, para
// seguir la conversación y para que el dueño vea qué preguntan (su asistente, herramienta preguntas_de_editores).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('book', 20); // lider o editor: el libro de ayuda que se usó
            $table->text('question');
            $table->text('answer')->nullable();
            $table->string('status', 10); // done o failed
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_questions');
    }
};
