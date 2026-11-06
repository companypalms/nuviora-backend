<?php

namespace App\Http\Controllers;

use App\Models\CalendarNote;
use App\Services\Calendar\Calendar;
use App\Services\Calendar\Rules\NoteRules;
use App\Services\Calendar\Rules\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * El calendario del dueño (Etapa 3). Solo el Admin, por la ruta. Las notas que anota el dueño y lo que marca como hecho o
 * descartado; no toca pedidos ni dinero. La misma API la usará el asistente de IA (guias/CALENDARIO.md).
 */
class CalendarController extends Controller
{
    /** Los días entre "from" y "to" (máximo 62), con lo que hay en cada uno. */
    public function index(Request $request, Calendar $calendar): JsonResponse
    {
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d']);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);
        if ($to->lt($from) || $from->diffInDays($to) + 1 > Calendar::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['to' => 'Pide como máximo ' . Calendar::MAX_RANGE_DAYS . ' días, y "hasta" después de "desde".']);
        }

        return response()->json($calendar->range($from, $to, CarbonImmutable::now()));
    }

    /** Lo de hoy, las atrasadas y cuántas quedan ("fresh=1": recién calculado, sin lo guardado del último minuto). */
    public function today(Request $request, Calendar $calendar): JsonResponse
    {
        return response()->json($calendar->today(CarbonImmutable::now(), $request->boolean('fresh')));
    }

    /** Las listas de la ventana "Anotar": tipos y cómo se repite. */
    public function options(): JsonResponse
    {
        return response()->json(['kinds' => NoteRules::KINDS, 'repeats' => Schedule::REPEATS]);
    }

    /** Los recordatorios del sistema: qué hace cada uno, cuándo toca y si está encendido. */
    public function settings(Calendar $calendar): JsonResponse
    {
        return response()->json($calendar->settings());
    }

    /** Enciende o apaga recordatorios y cambia el día de la revisión semanal ("rules": clave => {on, weekday}). */
    public function saveSettings(Request $request, Calendar $calendar): JsonResponse
    {
        $data = $request->validate(['rules' => 'required|array', 'rules.*.on' => 'sometimes|boolean', 'rules.*.weekday' => 'sometimes|nullable']);
        $result = $calendar->saveSettings($data['rules'], $request->user(), CarbonImmutable::now());

        return response()->json(['message' => 'Recordatorios guardados.'] + $result);
    }

    public function storeNote(Request $request, Calendar $calendar): JsonResponse
    {
        $source = $request->validate(['source' => ['nullable', Rule::in(NoteRules::SOURCES)]])['source'] ?? 'owner';
        $note = $calendar->createNote($request->all(), $request->user(), $source);

        return response()->json(['message' => 'Anotado.', 'id' => $note->id], 201);
    }

    public function updateNote(Request $request, CalendarNote $note, Calendar $calendar): JsonResponse
    {
        abort_if($note->archived_at !== null, 404, 'Esa nota ya se borró.');
        $calendar->updateNote($note, $request->all(), $request->user());

        return response()->json(['message' => 'Nota guardada.']);
    }

    public function archiveNote(Request $request, CalendarNote $note, Calendar $calendar): JsonResponse
    {
        abort_if($note->archived_at !== null, 404, 'Esa nota ya se borró.');
        $calendar->archiveNote($note, $request->user());

        return response()->json(['message' => 'Nota borrada.']);
    }

    /** Marca una aparición como hecha o descartada (due_date: el día que le tocaba de verdad). */
    public function mark(Request $request, Calendar $calendar): JsonResponse
    {
        $data = $request->validate([
            'item_key' => 'required|string|max:60',
            'due_date' => 'required|date_format:Y-m-d',
            'status' => ['required', Rule::in(['done', 'dismissed'])],
            'note' => 'nullable|string|max:250',
            'source' => ['nullable', Rule::in(NoteRules::SOURCES)],
        ]);
        $calendar->mark($data['item_key'], $data['due_date'], $data['status'], $data['note'] ?? null, $request->user(), $data['source'] ?? 'owner');

        return response()->json(['message' => $data['status'] === 'done' ? 'Listo, marcado como hecho.' : 'Descartado.']);
    }

    /** .107: la prioridad o los minutos de una o varias cosas, para todos los días ("items": [{item_key, priority?, minutes?}]). */
    public function savePlan(Request $request, Calendar $calendar): JsonResponse
    {
        $request->validate(['items' => 'required|array|min:1|max:60', 'items.*.item_key' => 'required|string|max:60']);
        $items = array_map(fn ($row) => array_intersect_key((array) $row, array_flip(['item_key', 'priority', 'minutes'])), array_values($request->input('items')));
        $calendar->savePlan($items, $request->user());

        return response()->json(['message' => 'Guardado para todos los días.']);
    }

    /** .107: el orden que puso arrastrando: la prioridad del grupo y sus cosas de arriba hacia abajo. */
    public function reorder(Request $request, Calendar $calendar): JsonResponse
    {
        $data = $request->validate(['priority' => 'required|string', 'item_keys' => 'required|array|min:1|max:100', 'item_keys.*' => 'required|string|max:60']);
        $calendar->reorder($data['priority'], $data['item_keys'], $request->user());

        return response()->json(['message' => 'Orden guardado para todos los días.']);
    }

    /** .107: "Volver al orden automático". */
    public function resetOrder(Calendar $calendar): JsonResponse
    {
        $calendar->resetOrder();

        return response()->json(['message' => 'Volvió el orden automático.']);
    }

    /** .108: el reloj de una tarea de hoy: start (empezar o seguir), pause, extend (+5 min) o later ("Más tarde"). */
    public function timer(Request $request, string $action, Calendar $calendar): JsonResponse
    {
        $data = $request->validate(['item_key' => 'required|string|max:60', 'due_date' => 'required|date_format:Y-m-d']);
        $calendar->timer($action, $data['item_key'], $data['due_date'], $request->user(), CarbonImmutable::now());

        return response()->json(['message' => match ($action) {
            'start' => 'El reloj está corriendo.',
            'pause' => 'En pausa.',
            'extend' => '5 minutos más.',
            default => 'La dejaste para después.',
        }]);
    }

    /** .108 "Tu hora": la zona en que el dueño ve y anota las horas del calendario. */
    public function saveTimezone(Request $request, Calendar $calendar): JsonResponse
    {
        $data = $request->validate(['timezone' => 'required|string|max:40']);
        $calendar->saveTimezone($data['timezone'], $request->user(), CarbonImmutable::now());

        return response()->json(['message' => 'Listo: las horas de tu calendario van en hora de ' . \App\Services\Calendar\CalendarSettings::ZONES[$data['timezone']] . '.']);
    }

    /** "Deshacer": vuelve a quedar pendiente. */
    public function unmark(Request $request, Calendar $calendar): JsonResponse
    {
        $data = $request->validate(['item_key' => 'required|string|max:60', 'due_date' => 'required|date_format:Y-m-d']);
        $calendar->unmark($data['item_key'], $data['due_date']);

        return response()->json(['message' => 'Vuelve a quedar pendiente.']);
    }
}
