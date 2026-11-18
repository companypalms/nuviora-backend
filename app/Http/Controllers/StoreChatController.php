<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StoreChatConversation;
use App\Models\StoreChatExamQuestion;
use App\Services\StoreChat\StoreChat;
use App\Services\StoreChat\StoreChatExam;
use App\Services\StoreChat\StoreChatReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * `.158` "Chat de la tienda" (solo el Admin): los ajustes, la página, el chat de prueba del dueño y el "Examen del chat"
 * (sus preguntas las cambia el dueño). El chat de los clientes en la tienda llega con la `.159`.
 */
class StoreChatController extends Controller
{
    public function index(StoreChatReport $report)
    {
        return response()->json($report->overview(CarbonImmutable::now()));
    }

    /** Prender o apagar, en qué productos (solo los que tienen ficha) y los topes. */
    public function saveSettings(Request $request, StoreChatReport $report)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'product_ids' => 'present|array|max:200',
            'product_ids.*' => 'integer',
            'daily_answers' => 'required|integer|min:10|max:5000',
            'per_visitor' => 'required|integer|min:3|max:100',
        ]);
        $withFicha = array_column($report->products(CarbonImmutable::now(), []), 'id');
        $missing = array_diff(array_map('intval', $data['product_ids']), $withFicha);
        abort_if($missing !== [], 422, 'Solo los productos con ficha pueden tener chat.');
        $saved = StoreChat::saveSettings($data);

        return response()->json(['message' => $saved['enabled'] ? 'Guardado. El chat está prendido.' : 'Guardado. El chat está apagado.', 'settings' => $saved]);
    }

    /** El dueño prueba el chat como si fuera un cliente (queda marcado "prueba"). */
    public function test(Request $request, StoreChat $chat)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'question' => 'required|string|max:2000',
            'visitor' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,40}$/'],
        ]);

        return response()->json($chat->ask(Product::findOrFail($data['product_id']), $data['visitor'], $data['question'],
            StoreChatConversation::TEST, null, CarbonImmutable::now()));
    }

    /** Empieza un examen: el número de esta vuelta y las preguntas. */
    public function examStart(Request $request, StoreChatExam $exam)
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id']);

        return response()->json($exam->start(Product::findOrFail($data['product_id']), CarbonImmutable::now()));
    }

    /** Una pregunta del examen, respondida y calificada. */
    public function examAsk(Request $request, StoreChatExam $exam)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'run' => ['required', 'string', 'regex:/^examen-[a-z0-9]{8,20}$/'],
            'question_id' => 'required|integer|exists:store_chat_exam_questions,id',
        ]);

        return response()->json($exam->ask(Product::findOrFail($data['product_id']), $data['run'],
            StoreChatExamQuestion::findOrFail($data['question_id']), CarbonImmutable::now()));
    }

    public function examCreate(Request $request, StoreChatExam $exam)
    {
        $exam->create($request->only(['group', 'question', 'expect']));

        return response()->json(['message' => 'Pregunta agregada al examen.', 'questions' => $exam->questions()]);
    }

    public function examUpdate(Request $request, StoreChatExamQuestion $question, StoreChatExam $exam)
    {
        $exam->update($question, $request->only(['group', 'question', 'expect']));

        return response()->json(['message' => 'Pregunta guardada.', 'questions' => $exam->questions()]);
    }

    public function examDelete(StoreChatExamQuestion $question, StoreChatExam $exam)
    {
        $exam->delete($question);

        return response()->json(['message' => 'Pregunta quitada del examen.', 'questions' => $exam->questions()]);
    }
}
