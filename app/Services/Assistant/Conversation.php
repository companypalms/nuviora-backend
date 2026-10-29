<?php

namespace App\Services\Assistant;

use App\Services\Assistant\Ai\AssistantModel;
use App\Services\Assistant\Tools\ToolCatalog;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Assistant\Tools\ToolResult;
use Throwable;

/**
 * Una respuesta del asistente: le da vueltas a la IA hasta que contesta. En cada vuelta la IA contesta o pide
 * herramientas; se consultan y se le devuelve lo que dijeron. Como mucho MAX_ROUNDS vueltas o MAX_SECONDS: en la última
 * tiene que contestar con lo que ya tiene. No toca la base de datos (Assistant guarda el resultado), así se prueba con
 * una IA y herramientas de mentira.
 */
class Conversation
{
    public const MAX_ROUNDS = 6;

    public const MAX_SECONDS = 90;

    /** Lo que lee la IA de cada herramienta, como mucho (lo demás se recorta). */
    public const MAX_TOOL_CHARS = 9000;

    /** Las tarjetas, gráficas y tablas que se ven debajo de una respuesta, como mucho. */
    public const MAX_DISPLAY = 8;

    public const FALLBACK = 'No alcancé a terminar la respuesta. Prueba preguntando algo más concreto.';

    /** @var callable(): float */
    private $clock;

    public function __construct(private AssistantModel $model, private ToolCatalog $tools, ?callable $clock = null)
    {
        $this->clock = $clock ?? fn () => microtime(true);
    }

    /**
     * @param callable(string): void $progress lo que se está revisando ("Revisando las ventas…")
     * @return array{text: string, display: array, calls: array, usage: array{input: int, cached: int, output: int}, model: ?string, rounds: int}
     */
    public function run(Turn $turn, callable $progress): array
    {
        $started = ($this->clock)();
        $usage = ['input' => 0, 'cached' => 0, 'output' => 0];
        $display = [];
        $calls = [];
        $model = null;

        for ($round = 1; ; $round++) {
            $last = $round >= self::MAX_ROUNDS || ($this->clock)() - $started > self::MAX_SECONDS;
            $reply = $this->model->next($turn->withMustAnswer($last));
            $model = $reply->model;
            foreach ($usage as $k => $v) {
                $usage[$k] = $v + (int) ($reply->usage[$k] ?? 0);
            }
            if ($reply->calls === [] || $last) {
                return [
                    'text' => $reply->text !== null && trim($reply->text) !== '' ? $reply->text : self::FALLBACK,
                    'display' => array_slice($display, 0, self::MAX_DISPLAY),
                    'calls' => $calls,
                    'usage' => $usage,
                    'model' => $model,
                    'rounds' => $round,
                ];
            }

            $outputs = [];
            foreach ($reply->calls as $call) {
                [$result, $ok] = $this->call($call['name'], $call['arguments'], $progress);
                $outputs[$call['call_id']] = self::output($result->data);
                $display = [...$display, ...$result->display];
                $calls[] = ['name' => $call['name'], 'arguments' => $call['arguments'], 'ok' => $ok];
            }
            $turn = $turn->withStep($reply, $outputs);
        }
    }

    /** @return array{0: ToolResult, 1: bool} */
    private function call(string $name, array $arguments, callable $progress): array
    {
        $tool = $this->tools->get($name);
        if ($tool === null) {
            return [ToolResult::problem("No existe la herramienta \"{$name}\"."), false];
        }
        $progress($tool->working($arguments));
        try {
            return [$tool->run($arguments), true];
        } catch (ToolProblem $e) {
            return [ToolResult::problem($e->getMessage()), false];
        } catch (Throwable $e) {
            report($e);

            return [ToolResult::problem('No se pudo consultar ahora (falló el sistema). Díselo al dueño y que lo intente de nuevo.'), false];
        }
    }

    /** Lo que lee la IA: JSON, recortado si es muy largo. */
    public static function output(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (mb_strlen($json) <= self::MAX_TOOL_CHARS) {
            return $json;
        }

        return mb_substr($json, 0, self::MAX_TOOL_CHARS) . '… (recortado: hay más datos de los que caben; pide algo más concreto si hace falta)';
    }
}
