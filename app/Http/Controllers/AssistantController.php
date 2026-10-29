<?php

namespace App\Http\Controllers;

use App\Models\AssistantMessage;
use App\Services\Assistant\Assistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El chat del asistente del dueño (Etapa 4). Solo el Admin, por la ruta. En este paso solo consulta: no cambia nada del
 * sistema. La respuesta se arma después de contestarle al navegador, que pregunta por ella (show) hasta que llega.
 */
class AssistantController extends Controller
{
    /** El chat: los mensajes, qué IA hay y cuántas preguntas van hoy. */
    public function index(Request $request, Assistant $assistant): JsonResponse
    {
        return response()->json([
            'mode' => $assistant->mode(),
            'messages' => $assistant->messages($request->user()),
            'today' => $assistant->todayCount($request->user()),
            'limit' => $assistant->dailyLimit(),
        ]);
    }

    /** Una pregunta nueva: se guarda y se contesta enseguida "pensando"; la respuesta se arma después. */
    public function store(Request $request, Assistant $assistant): JsonResponse
    {
        $data = $request->validate([
            'text' => 'required|string|max:' . Assistant::MAX_TEXT,
            'client_id' => 'nullable|string|max:40',
        ]);
        [$question, $reply] = $assistant->send($request->user(), $data['text'], $data['client_id'] ?? null);
        if ($reply->status === AssistantMessage::THINKING && $reply->wasRecentlyCreated) {
            app()->terminating(fn () => $assistant->answer($reply->id));
        }

        return response()->json([
            'question' => $question->toChat(),
            'reply' => $reply->toChat(),
            'today' => $assistant->todayCount($request->user()),
        ], 201);
    }

    /** Una respuesta, para saber si ya llegó (y qué está revisando). */
    public function show(Request $request, int $message, Assistant $assistant): JsonResponse
    {
        $found = $assistant->message($request->user(), $message);
        abort_unless($found, 404);

        return response()->json($found->toChat());
    }

    /** "Limpiar chat". */
    public function clear(Request $request, Assistant $assistant): JsonResponse
    {
        return response()->json(['cleared' => $assistant->clear($request->user())]);
    }
}
