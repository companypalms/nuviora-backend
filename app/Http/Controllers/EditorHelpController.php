<?php

namespace App\Http\Controllers;

use App\Services\Help\EditorHelp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La ayuda "¿Dudas?" de los editores (.96): el botón flotante de sus pantallas. Solo el líder y los editores de video, por
 * la ruta; cada uno con el libro de ayuda de su rol (Services\Help\EditorHelp).
 */
class EditorHelpController extends Controller
{
    /** La conversación de hoy, si la ayuda está encendida y cuántas preguntas le quedan. */
    public function index(Request $request, EditorHelp $help): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'mode' => $help->mode(),
            'book' => EditorHelp::book($user),
            'messages' => $help->messages($user),
            'left' => $help->left($user),
            'limit' => $help->dailyLimit($user), // .99: null para el líder (sin tope)
        ]);
    }

    public function store(Request $request, EditorHelp $help): JsonResponse
    {
        $data = $request->validate([
            'question' => 'required|string|max:' . EditorHelp::MAX_QUESTION,
        ], [
            'question.required' => 'Escribe tu duda.',
            'question.max' => 'Escribe tu duda en menos de ' . EditorHelp::MAX_QUESTION . ' letras.',
        ]);
        $asked = $help->ask($request->user(), $data['question']);

        return response()->json(['message' => $asked->toChat(), 'left' => $help->left($request->user())], 201);
    }
}
