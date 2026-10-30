<?php

use App\Models\AssistantAction;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ActionDesk;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Tools\ToolCatalog;
use App\Services\Assistant\Tools\ToolProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

// El asistente del dueño (Etapa 4, paso 5): las tarjetas de cambios con una herramienta de mentira, en una base en
// memoria con solo las tablas del chat (no toca la base local ni OpenAI).

/** Un cambio de mentira: cuenta cuántas veces se hizo. $estado simula lo que hay en el sistema (si cambia, la tarjeta vence). */
function cambioFalso(): ChangeTool
{
    return new class extends ChangeTool {
        public static int $hechos = 0;

        public static string $estado = 'A';

        public static ?Throwable $falla = null;

        public static ?string $secreto = null;

        /** Lo que cambia a cada rato (va en las notas, no en la huella). */
        public static string $nota = 'Tiene 1 orden';

        public function name(): string
        {
            return 'proponer_prueba';
        }

        public function description(): string
        {
            return 'De prueba';
        }

        public function parameters(): array
        {
            return ['type' => 'object', 'properties' => ['valor' => ['type' => 'string']], 'required' => ['valor'], 'additionalProperties' => false];
        }

        public function working(array $args): string
        {
            return 'Preparando…';
        }

        public function propose(array $args, ActionContext $context): Proposal
        {
            if (($args['valor'] ?? '') === '') {
                throw new ToolProblem('Falta el valor.');
            }

            return new Proposal('Cambiar el valor', "Cambiar a {$args['valor']}", [Proposal::line('Valor', (string) $args['valor'], self::$estado)], ['valor' => $args['valor']], [], [self::$nota]);
        }

        public function execute(array $payload, ActionContext $context): Outcome
        {
            if (self::$falla) {
                throw self::$falla;
            }
            self::$hechos++;

            return new Outcome("Listo: " . $payload['valor'] . ".", ['label' => 'Ver', 'to' => '/x'],
                self::$secreto ? ['label' => 'Contraseña', 'value' => self::$secreto, 'note' => 'Una sola vez'] : null);
        }
    };
}

beforeEach(function () {
    Schema::create('users', function ($t) {
        $t->id();
        $t->string('names')->nullable();
        $t->timestamps();
    });
    (require database_path('migrations/2026_10_29_400000_create_assistant_messages_table.php'))->up();
    (require database_path('migrations/2026_10_29_500000_create_assistant_actions_table.php'))->up();
    DB::table('users')->insert(['id' => 1, 'names' => 'Dueño']);
    $this->dueno = (new User())->forceFill(['id' => 1, 'names' => 'Dueño']);
    $this->dueno->exists = true;
    $this->tool = cambioFalso();
    $this->tool::$hechos = 0;
    $this->tool::$estado = 'A';
    $this->tool::$falla = null;
    $this->tool::$secreto = null;
    $this->tool::$nota = 'Tiene 1 orden';
    $this->desk = new ActionDesk(ToolCatalog::of($this->tool));
    $this->contexto = fn () => new ActionContext($this->dueno, null, [], CarbonImmutable::now());
    $this->proponer = function (string|float $valor, ?ActionContext $c = null) {
        $r = $this->desk->propose($this->tool, ['valor' => $valor], $c ?? ($this->contexto)());

        return [$r, isset($r->display[0]['id']) ? AssistantAction::find($r->display[0]['id']) : null];
    };
});

describe('Las tarjetas de cambios', function () {
    it('la IA solo propone: queda la tarjeta esperando y le dice que todavía no está hecho', function () {
        [$r, $a] = ($this->proponer)('B');
        expect($a->status)->toBe('proposed');
        expect($a->summary)->toBe('Cambiar a B');
        expect($a->toCard()['lines'])->toBe([['label' => 'Valor', 'value' => 'B', 'before' => 'A']]);
        expect($r->data['estado'])->toContain('Todavía NO está hecho');
        expect($r->display)->toBe([['type' => 'action', 'id' => $a->id]]);
        expect($this->tool::$hechos)->toBe(0);
        expect($a->expires_at->diffInMinutes(now()->addHours(2)))->toBeLessThan(1.0);
    });

    it('Confirmar lo hace una sola vez, aunque toque dos veces', function () {
        [, $a] = ($this->proponer)('B');
        $card = $this->desk->confirm($this->dueno, $a->id)->toCard();
        expect($card['status'])->toBe('done');
        expect($card['result'])->toBe(['text' => 'Listo: B.', 'link' => ['label' => 'Ver', 'to' => '/x']]);
        expect($this->desk->confirm($this->dueno, $a->id)->status)->toBe('done');
        expect($this->tool::$hechos)->toBe(1);
        expect($a->fresh()->decided_by)->toBe(1);
    });

    it('un número como 5.0 sigue siendo el mismo al confirmar (en la base vuelve como 5)', function () {
        [, $a] = ($this->proponer)(5.0);
        expect($this->desk->confirm($this->dueno, $a->id)->status)->toBe('done');
        expect($this->tool::$hechos)->toBe(1);
    });

    it('lo que cambia a cada rato (las notas) se ve, pero no hace vencer la tarjeta', function () {
        [, $a] = ($this->proponer)('B');
        expect($a->toCard()['notes'])->toBe(['Tiene 1 orden']);
        $this->tool::$nota = 'Tiene 2 órdenes'; // le llegó otra orden mientras tanto
        expect($this->desk->confirm($this->dueno, $a->id)->status)->toBe('done');
    });

    it('la contraseña de una cuenta nueva se ve una sola vez: no queda guardada en ningún lado', function () {
        $this->tool::$secreto = 'Clave-De-Prueba-1';
        [, $a] = ($this->proponer)('B');
        $hecha = $this->desk->confirm($this->dueno, $a->id);
        expect($hecha->toCard()['secret']['value'])->toBe('Clave-De-Prueba-1');
        expect(json_encode(DB::table('assistant_actions')->get()))->not->toContain('Clave-De-Prueba-1');
        expect($a->fresh()->toCard())->not->toHaveKey('secret');
        expect(json_encode($this->desk->history($this->dueno)))->not->toContain('Clave-De-Prueba-1');
        expect($this->desk->confirm($this->dueno, $a->id)->toCard())->not->toHaveKey('secret'); // un segundo clic ya no la muestra
    });

    it('Cancelar: ya no se puede confirmar', function () {
        [, $a] = ($this->proponer)('B');
        expect($this->desk->cancel($this->dueno, $a->id)->status)->toBe('cancelled');
        expect($this->desk->confirm($this->dueno, $a->id)->status)->toBe('cancelled');
        expect($this->tool::$hechos)->toBe(0);
    });

    it('vence a las 2 horas', function () {
        [, $a] = ($this->proponer)('B');
        $a->update(['expires_at' => now()->subMinute()]);
        expect($a->fresh()->toCard()['status'])->toBe('expired');
        expect($this->desk->confirm($this->dueno, $a->id)->status)->toBe('expired');
        expect($this->tool::$hechos)->toBe(0);
    });

    it('si algo cambió desde la propuesta, vence y pide repetirla', function () {
        [, $a] = ($this->proponer)('B');
        $this->tool::$estado = 'Z'; // otra persona cambió el dato mientras tanto
        $card = $this->desk->confirm($this->dueno, $a->id)->toCard();
        expect($card['status'])->toBe('expired');
        expect($card['error'])->toContain('Algo cambió');
        expect($this->tool::$hechos)->toBe(0);
    });

    it('si falla al hacerlo, queda como "no se pudo" con el motivo y sin guardar nada', function () {
        [, $a] = ($this->proponer)('B');
        $this->tool::$falla = ValidationException::withMessages(['x' => 'Esa cuenta está inactiva.']);
        $card = $this->desk->confirm($this->dueno, $a->id)->toCard();
        expect([$card['status'], $card['error']])->toBe(['failed', 'No se pudo hacer: Esa cuenta está inactiva. No se guardó nada.']);

        [, $b] = ($this->proponer)('C');
        $this->tool::$falla = new RuntimeException('se cayó la base');
        expect($this->desk->confirm($this->dueno, $b->id)->toCard()['error'])->toContain('falló el sistema')->not->toContain('base');
    });

    it('si falta algo, no hay tarjeta: la IA recibe el problema', function () {
        [$r, $a] = ($this->proponer)('');
        expect($a)->toBeNull();
        expect($r->data)->toBe(['problema' => 'Falta el valor.']);
        expect(AssistantAction::count())->toBe(0);
    });

    it('como mucho 3 por respuesta', function () {
        $c = ($this->contexto)();
        foreach (['B', 'C', 'D'] as $v) {
            expect(($this->proponer)($v, $c)[1])->not->toBeNull();
        }
        [$r, $a] = ($this->proponer)('E', $c);
        expect($a)->toBeNull();
        expect($r->data['problema'])->toContain('Ya hay 3 cambios propuestos');
    });

    it('solo el dueño de la tarjeta la ve y la confirma', function () {
        [, $a] = ($this->proponer)('B');
        DB::table('users')->insert(['id' => 2, 'names' => 'Otro']);
        $otro = (new User())->forceFill(['id' => 2]);
        expect(fn () => $this->desk->confirm($otro, $a->id))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
        expect($this->tool::$hechos)->toBe(0);
    });

    it('la respuesta muestra la tarjeta con su estado de ahora, y la IA sabe cómo terminó', function () {
        $q = AssistantMessage::create(['user_id' => 1, 'role' => 'user', 'text' => 'cámbialo', 'status' => 'done']);
        $reply = AssistantMessage::create(['user_id' => 1, 'role' => 'assistant', 'text' => 'Revisa la tarjeta', 'status' => 'done', 'reply_to_id' => $q->id]);
        [$r, $a] = ($this->proponer)('B', new ActionContext($this->dueno, $reply->id, [], CarbonImmutable::now()));
        $reply->update(['display' => [['type' => 'cards', 'cards' => []], ...$r->display]]);
        $this->desk->confirm($this->dueno, $a->id);

        $display = $reply->fresh()->toChat()['display'];
        expect($display[1]['type'])->toBe('action');
        expect($display[1]['action']['status'])->toBe('done');
        expect($this->desk->notes([$reply->id])[$reply->id])->toBe("\n[Tarjeta \"Cambiar a B\": el dueño la confirmó y quedó hecha.]");
        expect($this->desk->history($this->dueno)[0]['title'])->toBe('Cambiar el valor');
    });
});

describe('Lo que ve OpenAI de las herramientas', function () {
    it('las 44 son estrictas: cada objeto con todo requerido y nada de más (si no, OpenAI rechaza todas las preguntas)', function () {
        $check = function (array $schema, string $where) use (&$check) {
            if (isset($schema['anyOf'])) {
                foreach ($schema['anyOf'] as $i => $branch) {
                    $check($branch, "{$where}.anyOf{$i}");
                }

                return;
            }
            $types = (array) ($schema['type'] ?? []);
            if (in_array('object', $types, true)) {
                expect($schema['additionalProperties'] ?? null)->toBeFalse("{$where}: additionalProperties");
                $properties = (array) ($schema['properties'] ?? []); // sin parámetros es un objeto vacío
                expect(array_values($schema['required'] ?? []))->toBe(array_keys($properties), "{$where}: required");
                foreach ($properties as $name => $p) {
                    $check($p, "{$where}.{$name}");
                }
            }
            if (in_array('array', $types, true)) {
                $check($schema['items'] ?? [], "{$where}[]");
            }
        };
        $definitions = app(ToolCatalog::class)->definitions();
        expect(count($definitions))->toBe(44);
        foreach ($definitions as $d) {
            expect($d['strict'])->toBeTrue();
            $check($d['parameters'], $d['name']);
        }
        expect(array_column($definitions, 'name'))->toContain('proponer_gasto', 'proponer_tasas', 'proponer_banco', 'proponer_pago_gasto_fijo',
            'proponer_compra', 'proponer_compra_por_recibir', 'proponer_recibir_compra', 'proponer_ciudad_trabajando', 'proponer_agencia_trabajando',
            'proponer_usuario', 'proponer_desactivar_usuario', 'proponer_reactivar_usuario', 'efectivo_agencias',
            'analisis_campanas', 'campana_producto', 'creativos', 'angulos', 'cola_produccion');
    });
});
