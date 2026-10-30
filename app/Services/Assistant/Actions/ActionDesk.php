<?php

namespace App\Services\Assistant\Actions;

use App\Models\AssistantAction;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Services\Assistant\Tools\ToolCatalog;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Assistant\Tools\ToolResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Las tarjetas de cambios del asistente (Etapa 4, paso 5): proponer, confirmar y cancelar.
 * - La IA solo propone: se guarda la tarjeta (assistant_actions) y se ve en el chat con Confirmar y Cancelar.
 * - Confirmar lo pide el propio Admin desde el navegador, que solo manda el número de la tarjeta (nunca los datos). Se
 *   hace en ese momento, en una transacción y con la tarjeta bloqueada: dos clics no lo hacen dos veces.
 * - Antes de hacerlo se vuelve a revisar con las reglas de la pantalla. Si algo cambió desde la propuesta (por ejemplo,
 *   otra persona cambió la tasa), la tarjeta vence y hay que pedirlo de nuevo.
 * - Como el Admin está en la sesión, los registros de siempre (el historial de Finanzas, created_by) quedan a su nombre.
 */
class ActionDesk
{
    /** Las propuestas de una respuesta, como mucho. */
    public const MAX_PER_ANSWER = 3;

    public function __construct(private ToolCatalog $tools)
    {
    }

    public function propose(ChangeTool $tool, array $args, ActionContext $context): ToolResult
    {
        if ($context->proposals >= self::MAX_PER_ANSWER) {
            return ToolResult::problem('Ya hay ' . self::MAX_PER_ANSWER . ' cambios propuestos en esta respuesta: que el dueño los confirme primero y sigue después.');
        }
        // Tal como quedan guardados (5.0 vuelve como 5): así al confirmar la herramienta recibe exactamente lo mismo
        $args = json_decode(json_encode($args), true) ?? [];
        try {
            $proposal = $tool->propose($args, $context);
        } catch (ToolProblem $e) {
            return ToolResult::problem($e->getMessage());
        } catch (ValidationException $e) {
            return ToolResult::problem('Así no se puede: ' . implode(' ', Arr::flatten($e->errors())));
        }

        $action = AssistantAction::create([
            'user_id' => $context->user->id,
            'message_id' => $context->replyId,
            'tool' => $tool->name(),
            'args' => $args,
            'summary' => mb_strimwidth($proposal->summary, 0, 300, '…'),
            'card' => $proposal->card(),
            'fingerprint' => $proposal->fingerprint(),
            'status' => AssistantAction::PROPOSED,
            'expires_at' => $context->now->addHours(AssistantAction::EXPIRES_HOURS),
        ]);
        $context->proposals++;

        $data = [
            'propuesta' => $proposal->summary,
            'estado' => 'El dueño ve una tarjeta con Confirmar y Cancelar. Todavía NO está hecho: no digas que ya se hizo; dile que revise la tarjeta y toque Confirmar si está bien.',
        ];
        if ($proposal->warnings !== []) {
            $data['avisos'] = $proposal->warnings;
        }

        return new ToolResult($data, [['type' => 'action', 'id' => $action->id]]);
    }

    /** "Confirmar": lo hace, si todavía se puede. Devuelve la tarjeta como quedó. */
    public function confirm(User $user, int $id): AssistantAction
    {
        $action = AssistantAction::where('user_id', $user->id)->findOrFail($id);
        try {
            DB::transaction(function () use ($user, $id) {
                $locked = AssistantAction::whereKey($id)->lockForUpdate()->first();
                if ($locked->status !== AssistantAction::PROPOSED) {
                    return; // ya se decidió (un segundo clic)
                }
                if ($locked->currentStatus() === AssistantAction::EXPIRED) {
                    $locked->update(['status' => AssistantAction::EXPIRED]);

                    return;
                }
                $tool = $this->tools->get($locked->tool);
                if (!$tool instanceof ChangeTool) {
                    $locked->update(['status' => AssistantAction::FAILED, 'error' => 'Esta acción ya no existe.', 'decided_by' => $user->id, 'decided_at' => now()]);

                    return;
                }
                $context = $this->contextFor($locked, $user);
                try {
                    $fresh = $tool->propose($locked->args, $context);
                } catch (ToolProblem|ValidationException $e) {
                    $locked->update(['status' => AssistantAction::EXPIRED, 'error' => 'Ya no se puede: ' . self::reason($e) . ' Pídemelo de nuevo si hace falta.']);

                    return;
                }
                if (!hash_equals($locked->fingerprint, $fresh->fingerprint())) {
                    $locked->update(['status' => AssistantAction::EXPIRED, 'error' => 'Algo cambió desde que te lo propuse. Pídemelo de nuevo para ver la tarjeta al día.']);

                    return;
                }
                $outcome = $tool->execute($fresh->payload, $context);
                $locked->update(['status' => AssistantAction::DONE, 'result' => $outcome->toArray(), 'decided_by' => $user->id, 'decided_at' => now()]);
            });
        } catch (ValidationException|ToolProblem $e) {
            $this->fail($id, $user, 'No se pudo hacer: ' . self::reason($e) . ' No se guardó nada.');
        } catch (Throwable $e) {
            report($e);
            $this->fail($id, $user, 'No se pudo hacer: falló el sistema. No se guardó nada; intenta de nuevo en un momento.');
        }

        return $action->fresh();
    }

    /** "Cancelar". */
    public function cancel(User $user, int $id): AssistantAction
    {
        $action = AssistantAction::where('user_id', $user->id)->findOrFail($id);
        AssistantAction::whereKey($action->id)->where('status', AssistantAction::PROPOSED)
            ->update(['status' => AssistantAction::CANCELLED, 'decided_by' => $user->id, 'decided_at' => now()]);

        return $action->fresh();
    }

    /** "Lo que hizo el asistente": las últimas tarjetas, de la más nueva a la más vieja. */
    public function history(User $user, int $limit = 100): array
    {
        return AssistantAction::where('user_id', $user->id)->orderByDesc('id')->limit($limit)->get()
            ->map(fn (AssistantAction $a) => $a->toCard())->all();
    }

    /** Cómo terminaron las tarjetas de unas respuestas, para que la IA lo sepa en la pregunta siguiente. @return array<int, string> por respuesta */
    public function notes(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        $out = [];
        foreach (AssistantAction::whereIn('message_id', $messageIds)->orderBy('id')->get() as $a) {
            $state = match ($a->currentStatus()) {
                AssistantAction::DONE => 'el dueño la confirmó y quedó hecha',
                AssistantAction::CANCELLED => 'el dueño la canceló',
                AssistantAction::EXPIRED => 'venció sin confirmar' . ($a->error ? " ({$a->error})" : ''),
                AssistantAction::FAILED => 'no se pudo hacer' . ($a->error ? " ({$a->error})" : ''),
                default => 'todavía espera que el dueño la confirme',
            };
            $out[$a->message_id] = ($out[$a->message_id] ?? '') . "\n[Tarjeta \"{$a->summary}\": {$state}.]";
        }

        return $out;
    }

    private function contextFor(AssistantAction $action, User $user): ActionContext
    {
        $reply = $action->message_id ? AssistantMessage::find($action->message_id) : null;
        $question = $reply?->reply_to_id ? AssistantMessage::find($reply->reply_to_id) : null;

        return new ActionContext($user, $action->message_id, $question ? AssistantMessage::filesFor($question) : [], CarbonImmutable::now());
    }

    private function fail(int $id, User $user, string $error): void
    {
        AssistantAction::whereKey($id)->where('status', AssistantAction::PROPOSED)
            ->update(['status' => AssistantAction::FAILED, 'error' => mb_strimwidth($error, 0, 500, '…'), 'decided_by' => $user->id, 'decided_at' => now()]);
    }

    private static function reason(Throwable $e): string
    {
        return $e instanceof ValidationException ? implode(' ', Arr::flatten($e->errors())) : $e->getMessage();
    }
}
