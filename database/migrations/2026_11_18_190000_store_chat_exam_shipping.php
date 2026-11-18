<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `.162` (dueño, 2026-11-18: "el cliente paga al recibir en Valencia, Caracas y Maracay, el resto del país se debe pagar
 * anticipado y se envían todas las tardes por MRW, Tealca o Zoom… todas las entregas se realizan el mismo día"): dos
 * preguntas del examen que antes esperaban "pedir el WhatsApp" ahora tienen respuesta en las políticas de la tienda. Solo si el
 * dueño no las cambió. No borra nada.
 */
return new class extends Migration
{
    private const CHANGES = [
        '¿Llegan a Puerto Ayacucho?' => ['unknown', 'shipping'],
        '¿En cuántos días me llega?' => ['unknown', 'answer'],
    ];

    public function up(): void
    {
        foreach (self::CHANGES as $question => [$from, $to]) {
            DB::table('store_chat_exam_questions')->where('question', $question)->where('expect', $from)->update(['expect' => $to, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::CHANGES as $question => [$from, $to]) {
            DB::table('store_chat_exam_questions')->where('question', $question)->where('expect', $to)->update(['expect' => $from, 'updated_at' => now()]);
        }
    }
};
