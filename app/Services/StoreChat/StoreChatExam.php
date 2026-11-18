<?php

namespace App\Services\StoreChat;

use App\Models\Product;
use App\Models\StoreChatConversation;
use App\Models\StoreChatExamQuestion;
use App\Models\StoreChatMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * El "Examen del chat" (`.158`): el dueño elige un producto y el chat responde, una por una, las preguntas del examen
 * (correctas, difíciles, capciosas y maliciosas); cada respuesta queda calificada (StoreChatExamRules). Cada pregunta va
 * sola, como un cliente nuevo; los exámenes no cuentan para los topes ni salen en las conversaciones.
 */
class StoreChatExam
{
    public function __construct(private StoreChat $chat, private StoreChatFacts $facts)
    {
    }

    /** @return list<array{id: int, group: string, question: string, expect: string}> las preguntas, por grupo y en su orden */
    public function questions(): array
    {
        $order = array_flip(array_keys(StoreChatExamRules::GROUPS));

        return StoreChatExamQuestion::orderBy('position')->orderBy('id')->get()
            ->sortBy(fn ($q) => [$order[$q->group] ?? 99, $q->position, $q->id])
            ->map(fn ($q) => ['id' => $q->id, 'group' => $q->group, 'question' => $q->question, 'expect' => $q->expect])
            ->values()->all();
    }

    /** Empieza un examen: un número para esta vuelta y las preguntas que va a responder. */
    public function start(Product $product, CarbonImmutable $now): array
    {
        abort_unless(StoreChatRules::hasFicha($this->facts->for($product, $now)['ficha']), 422,
            'Este producto no tiene ficha: el chat no sabría qué responder. Llénala en Integraciones › Meta Ads › Ficha del producto.');
        $questions = $this->questions();
        abort_if($questions === [], 422, 'El examen no tiene preguntas: agrega alguna.');

        return ['run' => 'examen-' . Str::lower(Str::random(12)), 'questions' => $questions];
    }

    /** Responde una pregunta del examen y la califica. */
    public function ask(Product $product, string $run, StoreChatExamQuestion $q, CarbonImmutable $now): array
    {
        $reply = $this->chat->ask($product, $run, $q->question, StoreChatConversation::EXAM, null, $now);
        $grade = StoreChatExamRules::grade($q->expect, $reply, $this->facts->for($product, $now), $q->question);
        StoreChatMessage::whereKey($reply['id'])->update(['exam_question_id' => $q->id, 'exam_ok' => $grade['ok'], 'exam_note' => $grade['note']]);

        return $reply + ['question_id' => $q->id, 'group' => $q->group, 'expect' => $q->expect, 'ok' => $grade['ok'], 'grade' => $grade['note']];
    }

    /** @return array<int, array{ok: int, total: int, at: ?string}> el último examen de cada producto (cuántas bien de cuántas) */
    public function lastByProduct(): array
    {
        $last = StoreChatConversation::where('source', StoreChatConversation::EXAM)->where('questions', '>', 0)
            ->selectRaw('product_id, MAX(id) as id')->groupBy('product_id')->pluck('id', 'product_id');
        $out = [];
        foreach (StoreChatConversation::whereIn('id', $last->values())->withCount([
            'messages as total',
            'messages as ok' => fn ($q) => $q->where('exam_ok', true),
        ])->get() as $c) {
            $out[$c->product_id] = ['ok' => (int) $c->ok, 'total' => (int) $c->total, 'at' => $c->updated_at?->toIso8601String()];
        }

        return $out;
    }

    public function create(array $data): StoreChatExamQuestion
    {
        $this->validate($data);

        return StoreChatExamQuestion::create([
            'group' => $data['group'], 'question' => trim($data['question']), 'expect' => $data['expect'],
            'position' => (int) StoreChatExamQuestion::max('position') + 1,
        ]);
    }

    public function update(StoreChatExamQuestion $q, array $data): StoreChatExamQuestion
    {
        $this->validate($data);
        $q->update(['group' => $data['group'], 'question' => trim($data['question']), 'expect' => $data['expect']]);

        return $q;
    }

    /** Quita la pregunta del examen; los exámenes viejos guardan lo que se respondió. */
    public function delete(StoreChatExamQuestion $q): void
    {
        StoreChatMessage::where('exam_question_id', $q->id)->update(['exam_question_id' => null]);
        $q->delete();
    }

    private function validate(array $data): void
    {
        $problem = StoreChatExamRules::problem($data);
        abort_if($problem !== null, 422, (string) $problem);
    }
}
