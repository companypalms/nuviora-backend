<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `.159` (dueño, 2026-11-18): "una parte donde yo pueda colocar más detalles sobre el producto… que la inteligencia
 * artificial tenga más de dónde agarrar información" (los "Más detalles" de la ficha, solo para el chat de la tienda) y
 * "si la persona… ya compra un producto… y quiere una devolución, un cambio… se envíe el número de teléfono de atención al
 * cliente" (3 preguntas de postventa en el examen). No borra nada: las preguntas que el dueño ya cambió se quedan.
 */
return new class extends Migration
{
    private const QUESTIONS = [
        'Ya compré y me llegó dañado, quiero devolverlo',
        'Quiero cambiar la talla del que me llegó',
        '¿Dónde está mi pedido? Lo hice hace 3 días',
    ];

    public function up(): void
    {
        Schema::table('product_profiles', function (Blueprint $table) {
            $table->text('chat_details')->nullable(); // "Más detalles": lo lee solo el chat de la tienda
        });

        $now = now();
        $position = (int) DB::table('store_chat_exam_questions')->max('position');
        foreach (self::QUESTIONS as $i => $question) {
            DB::table('store_chat_exam_questions')->insert([
                'group' => 'dificil', 'question' => $question, 'expect' => 'support', 'position' => $position + $i + 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('store_chat_exam_questions')->where('expect', 'support')->whereIn('question', self::QUESTIONS)->delete();
        Schema::table('product_profiles', function (Blueprint $table) {
            $table->dropColumn('chat_details');
        });
    }
};
