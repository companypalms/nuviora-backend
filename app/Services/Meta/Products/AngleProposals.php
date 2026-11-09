<?php

namespace App\Services\Meta\Products;

use App\Models\AdAngle;
use App\Models\AngleProposal;
use App\Models\Product;
use App\Models\User;
use App\Services\Calendar\Calendar;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Queue\QueueProposer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * `.116` (dueño, 2026-11-09: "lo de que la IA propone un nuevo ángulo no me gusta tanto. Me gustaría que me lo propusiera a
 * mí solamente, en mi calendario y en mis tareas por hacer en el día, y yo veo si le digo que hagamos ese ángulo, otro que
 * yo recomiende, o uno de los que ya está"). Cuando a un producto no le quedan ángulos disponibles, la IA propone uno
 * leyendo su ficha y lo que sabemos; el dueño decide y el siguiente encargo del líder sale con ese ángulo.
 * Sin OPENAI_API_KEY: en la PC de pruebas, un ejemplo.
 */
class AngleProposals
{
    private const INSTRUCTIONS = <<<'TXT'
Eres el estratega creativo de una tienda online de Venezuela que vende productos con anuncios de Meta (Facebook e Instagram) y cobra contra entrega.
A un producto ya no le quedan ángulos disponibles para probar. Un ángulo le habla a un dolor, un deseo o un público. Propón un solo ángulo nuevo, distinto de todos los que ya se probaron.
Usa solo lo que dice la ficha del producto: nunca le agregues usos ni beneficios que no estén ahí y nunca contradigas "lo que NO es". Las promesas del dueño se usan tal cual, sin suavizarlas ni agregar advertencias; no inventes médicos, estudios ni testimonios.
Responde:
- codigo: un código de 3 a 6 letras mayúsculas para el ángulo, que no esté en la lista.
- nombre: un nombre corto en español (2 a 5 palabras), por ejemplo "Dolor de tobillo al caminar".
- de_que_trata: una o dos frases: a quién le habla, qué dolor o deseo toca y por qué compraría.
- por_que: una o dos frases: por qué probarías este ángulo, según lo que funcionó y lo que falló. Si no hay datos, dilo.
Escribe en español sencillo.
TXT;

    private const SCHEMA = [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['codigo', 'nombre', 'de_que_trata', 'por_que'],
        'properties' => ['codigo' => ['type' => 'string'], 'nombre' => ['type' => 'string'], 'de_que_trata' => ['type' => 'string'], 'por_que' => ['type' => 'string']],
    ];

    /** La propuesta de un producto sin ángulos disponibles. Si ya tiene una por decidir, no se hace otra. */
    public function ensure(int $productId): void
    {
        if (AngleProposal::where('product_id', $productId)->where('status', AngleProposal::PENDING)->exists()) {
            return;
        }
        AngleProposal::create(['product_id' => $productId, 'status' => AngleProposal::PENDING] + $this->suggestion($productId));
        Calendar::forgetToday();
    }

    /** Lo que ve el dueño para decidir: la propuesta y los ángulos de la ficha, con cuáles descansan o perdieron. */
    public function show(AngleProposal $p): array
    {
        $rests = [];
        foreach (app(MetaDecisions::class)->forProducts([(int) $p->product_id])[$p->product_id]['plan']['rests'] ?? [] as $r) {
            $rests[$r['angle']] = $r['until'];
        }
        $lost = TestingPhase::profileAngles((int) $p->product_id)['lost'];
        $product = Product::find($p->product_id, ['id', 'title', 'showable_name']);

        return [
            'id' => $p->id, 'product' => $product?->showable_name ?: $product?->title, 'status' => $p->status,
            'code' => $p->code, 'name' => $p->name, 'about' => $p->about, 'why' => $p->why,
            'angles' => array_map(fn ($a) => ['code' => $a['code'], 'name' => $a['name'],
                'note' => isset($rests[$a['code']]) ? 'Descansa ' . AngleRest::untilText($rests[$a['code']]) : (in_array($a['code'], $lost, true) ? 'Perdió en el testeo' : null)],
                TestingPhase::angles((int) $p->product_id)),
        ];
    }

    /**
     * Lo que decidió el dueño.
     *
     * @param array{choice: string, name?: ?string, about?: ?string, code?: ?string} $in
     * @return string el ángulo elegido
     */
    public function decide(AngleProposal $p, array $in, User $by): string
    {
        if ($p->status !== AngleProposal::PENDING) {
            throw ValidationException::withMessages(['choice' => 'Esta propuesta ya se decidió.']);
        }
        $angle = DB::transaction(fn () => match ($in['choice']) {
            'accepted' => $this->addAngle($p, $p->code ?: (string) $p->name, (string) $p->name, $p->about, 'ai', $by),
            'other' => $this->addAngle($p, (string) $in['name'], (string) $in['name'], $in['about'] ?? null, 'owner', $by),
            default => $this->existing($p, (string) ($in['code'] ?? '')),
        });
        $p->forceFill(['status' => $in['choice'], 'decided_angle' => $angle, 'decided_by' => $by->id, 'decided_at' => now()])->save();
        Calendar::forgetToday();
        QueueProposer::later();

        return $angle;
    }

    /** "Dolor de espalda" → "DOLESP"; si ya existe en el producto, con un número. */
    public static function code(string $text, array $taken): string
    {
        $plain = strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text);
        $words = array_values(array_filter(preg_split('/[^A-Z0-9]+/', $plain), fn ($w) => strlen($w) > 2));
        $base = count($words) === 1 ? substr($words[0], 0, 6) : implode('', array_map(fn ($w) => substr($w, 0, 3), array_slice($words, 0, 2)));
        $base = $base !== '' ? $base : 'ANG';
        $code = $base;
        for ($i = 2; in_array($code, $taken, true); $i++) {
            $code = substr($base, 0, 5) . $i;
        }

        return $code;
    }

    /** La propuesta de la IA (o nada que proponer, si falla: el dueño elige igual). */
    private function suggestion(int $productId): array
    {
        $taken = AdAngle::where('product_id', $productId)->pluck('code')->all();
        if (!config('services.openai.key')) {
            return app()->environment('local') ? self::clean(self::example(), $taken) : ['why' => 'La IA no está configurada en este servidor.'];
        }
        try {
            return self::clean($this->ask($this->input($productId, $taken)), $taken);
        } catch (RuntimeException $e) {
            report($e);

            return ['why' => null];
        }
    }

    /** Lo que vuelve de la IA, limpio: el código en mayúsculas y sin repetir uno que ya existe. */
    public static function clean(array $a, array $taken): array
    {
        $code = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($a['codigo'] ?? '')));

        return ['code' => $code !== '' && !in_array($code, $taken, true) ? substr($code, 0, 20) : self::code((string) ($a['nombre'] ?? ''), $taken),
            'name' => mb_substr(trim((string) ($a['nombre'] ?? '')), 0, 120) ?: null, 'about' => trim((string) ($a['de_que_trata'] ?? '')) ?: null, 'why' => trim((string) ($a['por_que'] ?? '')) ?: null];
    }

    /** Lo que lee la IA: la ficha, los ángulos que ya existen y lo que sabemos del producto. */
    private function input(int $productId, array $taken): string
    {
        $product = Product::find($productId, ['id', 'title', 'showable_name']);
        $f = ProductProfiles::get($productId);

        return implode("\n", array_filter(["PRODUCTO: " . ($product?->showable_name ?: $product?->title),
            'Ficha del producto:', ProductProfileRules::forAi($f['profile'], $f['personas']),
            'Ángulos que ya existen (no los repitas): ' . implode(', ', $taken),
            'Lo que sabemos de este producto:', ProductMemories::forWriter($productId) ?: '  · (todavía sin datos)']));
    }

    private function ask(string $input): array
    {
        $response = Http::withToken((string) config('services.openai.key'))->acceptJson()->timeout(60)->post('https://api.openai.com/v1/responses', [
            'model' => (string) config('services.openai.meta_analysis_model', config('services.openai.insights_model', 'gpt-6-luna')),
            'store' => false,
            'reasoning' => ['effort' => 'low'],
            'instructions' => self::INSTRUCTIONS,
            'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $input]]]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'angulo_nuevo', 'strict' => true, 'schema' => self::SCHEMA]],
        ]);
        if ($response->failed()) {
            throw new RuntimeException('OpenAI respondió ' . $response->status() . '.');
        }
        foreach ($response->json('output') ?? [] as $item) {
            foreach (($item['type'] ?? null) === 'message' ? ($item['content'] ?? []) : [] as $content) {
                if (($content['type'] ?? null) === 'output_text' && is_array($data = json_decode($content['text'], true))) {
                    return $data;
                }
            }
        }
        throw new RuntimeException('La IA respondió sin el formato esperado.');
    }

    private static function example(): array
    {
        return ['codigo' => 'EJEMPL', 'nombre' => 'Ángulo de ejemplo', 'de_que_trata' => 'Propuesta de ejemplo: en la PC de pruebas no hay IA conectada.',
            'por_que' => 'Es solo para ver cómo funciona la decisión.'];
    }

    /** Un ángulo nuevo, al final de la ficha. */
    private function addAngle(AngleProposal $p, string $codeFrom, string $name, ?string $about, string $by, User $user): string
    {
        $pid = (int) $p->product_id;
        $code = $p->code && $by === 'ai' ? $p->code : self::code($codeFrom, AdAngle::where('product_id', $pid)->pluck('code')->all());
        if (trim($name) === '') {
            throw ValidationException::withMessages(['name' => 'Escribe el nombre del ángulo.']);
        }
        AdAngle::updateOrCreate(['product_id' => $pid, 'code' => $code], ['name' => trim($name), 'hypothesis' => $about !== null && trim($about) !== '' ? trim($about) : null,
            'position' => (int) AdAngle::where('product_id', $pid)->max('position') + 1, 'status' => 'confirmed', 'proposed_by' => $by, 'confirmed_by' => $user->id, 'confirmed_at' => now()]);

        return $code;
    }

    /** Uno de los que ya están en la ficha. */
    private function existing(AngleProposal $p, string $code): string
    {
        if (!in_array($code, array_column(TestingPhase::angles((int) $p->product_id), 'code'), true)) {
            throw ValidationException::withMessages(['code' => 'Elige uno de los ángulos de la ficha.']);
        }

        return $code;
    }
}
