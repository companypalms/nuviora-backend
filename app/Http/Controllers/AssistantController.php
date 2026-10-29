<?php

namespace App\Http\Controllers;

use App\Models\AssistantMessage;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\Files\Attachments;
use App\Services\Assistant\Files\FileRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El chat del asistente del dueño (Etapa 4). Solo el Admin, por la ruta. Por ahora solo consulta: no cambia nada del
 * sistema. La respuesta se arma después de contestarle al navegador, que pregunta por ella (show) hasta que llega. Desde el
 * paso 4 la pregunta puede llevar fotos, PDF o Excel, y se puede dictar con el micrófono (transcribe).
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

    /**
     * Una pregunta nueva, con archivos o sin ellos (fotos, PDF, Excel o CSV): se guarda y se contesta enseguida "pensando";
     * la respuesta se arma después. Los tipos y tamaños los revisa Files\FileRules (por el contenido del archivo).
     */
    public function store(Request $request, Assistant $assistant): JsonResponse
    {
        $data = $request->validate([
            'text' => 'nullable|string|required_without:files|max:' . Assistant::MAX_TEXT,
            'client_id' => 'nullable|string|max:40',
            'files' => 'nullable|array|max:' . FileRules::MAX_FILES,
            'files.*' => 'file|max:' . (FileRules::DOC_MAX_BYTES / 1024),
        ], [
            'text.required_without' => 'Escribe una pregunta o adjunta un archivo.',
            'files.max' => 'Puedes mandar hasta ' . FileRules::MAX_FILES . ' archivos por mensaje.',
            'files.*.max' => 'Cada archivo puede pesar hasta 20 MB.',
            'files.*.uploaded' => 'No se pudo subir el archivo (máximo 20 MB).',
        ]);
        [$question, $reply] = $assistant->send($request->user(), (string) ($data['text'] ?? ''), $data['client_id'] ?? null, $request->file('files', []));
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

    /** El micrófono: lo que dijo, en texto, para la caja del chat (el audio no se guarda). */
    public function transcribe(Request $request, Assistant $assistant): JsonResponse
    {
        $request->validate(['audio' => 'required|file|max:25600'], [
            'audio.required' => 'No llegó el audio. Prueba de nuevo.',
            'audio.max' => 'La grabación es muy larga (máximo 25 MB).',
            'audio.uploaded' => 'No se pudo subir el audio. Prueba de nuevo.',
        ]);

        return response()->json($assistant->transcribe($request->file('audio')));
    }

    /**
     * GET /assistant/attachments/{message}/{file}: un archivo del chat. Sin sesión (se abre como <img> o en otra
     * pestaña), pero solo con el enlace firmado que entrega el chat al Admin (12 h), como el chat interno.
     */
    public function attachment(Request $request, AssistantMessage $message, int $file, Attachments $files)
    {
        abort_unless($request->hasValidSignatureWhileIgnoring(['download']), 403, 'Enlace no válido o vencido. Vuelve a abrir el chat.');
        $entry = ($message->attachments ?? [])[$file] ?? null;
        abort_unless(is_array($entry), 404, 'El archivo ya no está.');

        return $files->response($entry, $request->boolean('download'));
    }
}
