<?php

namespace App\Services\Calendar;

use App\Models\CalendarMark;
use App\Models\CalendarNote;
use App\Models\User;
use App\Services\Calendar\Rules\NoteRules;
use App\Services\Calendar\Rules\Schedule;
use App\Services\Metrics\PersonName;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * El calendario del dueño (Etapa 3): junta lo que hay que hacer cada día y lo que ya se hizo. Solo el Admin (la ruta).
 *
 * Cada cosa del calendario es una "aparición" con la misma forma, venga de una nota del dueño o (en los próximos pasos) de
 * un recordatorio del sistema. Es la misma forma que leerá el asistente de IA (guias/CALENDARIO.md):
 *   item_key   de quién es: "note:12" (y después "rule:…", "expense:…")
 *   date       el día en que se muestra; due_date el día que le tocaba de verdad (distinto si se corrió por un domingo)
 *   type       payment | task | review
 *   source     owner | assistant | system
 *   title, details, time, repeat_label, text (todo en una frase)
 *   status     pending | done | dismissed | overdue (atrasada) | missed (pasó y no se acumula)
 *   mark       quién la marcó, cuándo y con qué nota
 */
class Calendar
{
    /** Las atrasadas se buscan hasta este número de días atrás (y nunca antes del primer día del calendario). */
    public const OVERDUE_DAYS = 60;

    /** El rango más largo que se pide de una vez (un mes con sus semanas de los bordes). */
    public const MAX_RANGE_DAYS = 62;

    private const TYPE_ORDER = ['payment' => 0, 'task' => 1, 'review' => 2];

    public function __construct(private CalendarSettings $settings)
    {
    }

    /** Los días entre dos fechas, cada uno con sus apariciones y su estado. */
    public function range(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $now): array
    {
        $today = $now->toDateString();
        $days = [];
        for ($d = $from->startOfDay(); $d->lte($to); $d = $d->addDay()) {
            $days[$d->toDateString()] = [];
        }
        foreach ($this->withStatus($this->occurrences($from, $to), $today) as $item) {
            $days[$item['date']][] = $item;
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'today' => $today,
            'started_on' => $this->settings->startedOn($now),
            'days' => $days,
        ];
    }

    /** Lo de hoy, las atrasadas y cuántas quedan, para el calendario, el Dashboard y el asistente. */
    public function today(CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $items = $this->withStatus($this->occurrences($today, $today), $today->toDateString());
        $overdue = $this->overdue($now);
        $pending = count(array_filter($items, fn ($i) => $i['status'] === 'pending'));
        $done = count(array_filter($items, fn ($i) => $i['status'] === 'done'));

        return [
            'date' => $today->toDateString(),
            'label' => ucfirst(Schedule::dayName($today->toDateString())),
            'items' => $items,
            'overdue' => $overdue,
            'counts' => ['pending' => $pending, 'overdue' => count($overdue), 'done' => $done],
            'text' => self::summary($pending, count($overdue)),
        ];
    }

    /** Lo que quedó sin hacer en días anteriores y se acumula, de lo más viejo a lo más nuevo. */
    public function overdue(CarbonImmutable $now): array
    {
        $started = CarbonImmutable::parse($this->settings->startedOn($now));
        $from = $now->startOfDay()->subDays(self::OVERDUE_DAYS)->max($started);
        $to = $now->startOfDay()->subDay();
        if ($from->gt($to)) {
            return [];
        }
        $items = array_filter($this->withStatus($this->occurrences($from, $to), $now->toDateString()), fn ($i) => $i['status'] === 'overdue');
        usort($items, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return array_values($items);
    }

    /** Marca una aparición como hecha o descartada (con una nota si se quiere). Solo un día que le toca. */
    public function mark(string $itemKey, string $due, string $status, ?string $note, User $by, string $source = 'owner'): void
    {
        if (!$this->exists($itemKey, $due)) {
            throw ValidationException::withMessages(['item_key' => 'Eso ya no está en el calendario en esa fecha. Actualiza la página.']);
        }
        CalendarMark::updateOrCreate(
            ['item_key' => $itemKey, 'occurs_on' => $due],
            ['status' => $status, 'note' => $note, 'source' => $source, 'marked_by' => $by->id],
        );
    }

    /** "Deshacer": la aparición vuelve a quedar pendiente. */
    public function unmark(string $itemKey, string $due): void
    {
        CalendarMark::where('item_key', $itemKey)->whereDate('occurs_on', $due)->delete();
    }

    /** Anota una nota nueva (del dueño o, más adelante, del asistente). */
    public function createNote(array $input, User $by, string $source = 'owner'): CalendarNote
    {
        $fields = $this->checked($input);

        return CalendarNote::create($fields + ['source' => $source, 'created_by' => $by->id, 'updated_by' => $by->id]);
    }

    public function updateNote(CalendarNote $note, array $input, User $by): CalendarNote
    {
        $note->update($this->checked($input) + ['updated_by' => $by->id]);

        return $note;
    }

    /** "Borrar" una nota: se archiva y deja de salir en el calendario (lo que se tildó queda guardado). */
    public function archiveNote(CalendarNote $note, User $by): void
    {
        $note->update(['archived_at' => now(), 'archived_by' => $by->id]);
    }

    /** Hoy en una frase: "Hoy tienes 3 cosas por hacer y 1 atrasada." */
    public static function summary(int $pending, int $overdue): string
    {
        if ($pending === 0 && $overdue === 0) {
            return 'Hoy no tienes nada pendiente.';
        }
        $parts = [];
        if ($pending > 0) {
            $parts[] = $pending === 1 ? '1 cosa por hacer' : "{$pending} cosas por hacer";
        }
        if ($overdue > 0) {
            $parts[] = $overdue === 1 ? '1 atrasada' : "{$overdue} atrasadas";
        }

        return 'Hoy tienes ' . implode(' y ', $parts) . '.';
    }

    /** Lo que mandó la pantalla, limpio y revisado; si algo está mal, avisa qué casilla. */
    private function checked(array $input): array
    {
        $fields = NoteRules::normalize($input);
        if ($problems = NoteRules::problems($fields)) {
            throw ValidationException::withMessages($problems);
        }

        return $fields;
    }

    /** Si esa cosa existe y le toca ese día. */
    private function exists(string $itemKey, string $due): bool
    {
        if (preg_match('/^note:(\d+)$/', $itemKey, $m)) {
            $note = CalendarNote::active()->find((int) $m[1]);

            return $note !== null && Schedule::isDue($note->pattern(), $due);
        }

        return false;
    }

    /**
     * Las apariciones que se muestran entre dos días, todavía sin estado. Por ahora, las notas; los recordatorios del
     * sistema (pagos, tareas de todos los días, revisiones) llegan en los próximos pasos.
     *
     * @return list<array>
     */
    private function occurrences(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Un día de más a cada lado: lo que cae domingo se corre al sábado o al lunes
        $lo = $from->subDay()->toDateString();
        $hi = $to->addDay()->toDateString();
        $notes = CalendarNote::active()
            ->where('date', '<=', $hi)
            ->where(fn ($q) => $q->where('repeat', '!=', 'none')->orWhere('date', '>=', $lo))
            ->where(fn ($q) => $q->whereNull('repeat_until')->orWhere('repeat_until', '>=', $lo))
            ->get();

        $items = [];
        foreach ($notes as $note) {
            $fields = $note->fields();
            $pattern = $note->pattern();
            foreach (Schedule::dates($pattern, $from, $to) as $d) {
                $items[] = [
                    'item_key' => $note->itemKey(),
                    'date' => $d['date'],
                    'due_date' => $d['due'],
                    'type' => $fields['kind'],
                    'source' => $note->source,
                    'title' => $fields['title'],
                    'details' => $fields['details'],
                    'time' => $fields['time'],
                    'repeat_label' => Schedule::label($pattern),
                    'base_text' => NoteRules::text($fields),
                    'accumulates' => true,
                    'auto' => false,
                    'link' => null,
                    'note_id' => $note->id,
                    'note_fields' => $fields,
                ];
            }
        }

        return $items;
    }

    /** Les suma a las apariciones cómo están (hecha, descartada, pendiente, atrasada) y su frase, y las ordena. */
    private function withStatus(array $items, string $today): array
    {
        if ($items === []) {
            return [];
        }
        $marks = CalendarMark::with('marker:id,names,surnames')
            ->whereIn('item_key', array_unique(array_column($items, 'item_key')))
            ->whereIn('occurs_on', array_unique(array_column($items, 'due_date')))
            ->get()
            ->keyBy(fn (CalendarMark $m) => $m->item_key . '|' . $m->occurs_on->toDateString());

        foreach ($items as &$item) {
            $mark = $marks->get($item['item_key'] . '|' . $item['due_date']);
            $item['status'] = Schedule::status($mark?->status, $item['date'], $today, $item['accumulates']);
            $item['text'] = Schedule::sentence($item['base_text'], $item['date'], $item['due_date'], $item['status']);
            $item['mark'] = $mark ? [
                'status' => $mark->status,
                'note' => $mark->note,
                'source' => $mark->source,
                'by' => $mark->marker ? PersonName::of($mark->marker->names, $mark->marker->surnames) : null,
                'at' => $mark->updated_at?->format('Y-m-d H:i'),
            ] : null;
            unset($item['base_text']);
        }
        unset($item);

        // Primero lo pendiente; después por hora (lo que no tiene hora al final), tipo y título
        usort($items, fn ($a, $b) => [self::closed($a), $a['time'] ?? '99:99', self::TYPE_ORDER[$a['type']] ?? 9, $a['title']]
            <=> [self::closed($b), $b['time'] ?? '99:99', self::TYPE_ORDER[$b['type']] ?? 9, $b['title']]);

        return $items;
    }

    private static function closed(array $item): int
    {
        return in_array($item['status'], ['done', 'dismissed'], true) ? 1 : 0;
    }
}
