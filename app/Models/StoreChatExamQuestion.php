<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una pregunta del "Examen del chat" (`.158`): su grupo (correcta, difícil, capciosa o maliciosa), el texto y lo que debe
 * hacer la respuesta (StoreChatExamRules::EXPECTS). El dueño las cambia, quita y agrega.
 */
class StoreChatExamQuestion extends Model
{
    protected $fillable = ['group', 'question', 'expect', 'position'];
}
