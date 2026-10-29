<?php

namespace App\Services\Calendar;

use App\Models\CalendarMark;
use App\Models\CalendarNote;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Services\Calendar\Readers\ReminderFacts;
use App\Services\Calendar\Rules\ExpenseRules;
use App\Services\Calendar\Rules\NoteRules;
use App\Services\Finance\OtherPayments;
use App\Services\Calendar\Rules\ReminderRules;
use App\Services\Calendar\Rules\Schedule;
use App\Services\Metrics\PersonName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * El calendario del dueño (Etapa 3): junta lo que hay que hacer cada día y lo que ya se hizo. Solo el Admin (la ruta).
 *
 * Cada cosa del calendario es una "aparición" con la misma forma, venga de una nota del dueño, de un recordatorio del
 * sistema (ReminderRules) o de un gasto fijo de Finanzas (ExpenseRules). Es la misma forma que leerá el asistente de IA
 * (guias/CALENDARIO.md):
 *   item_key   de quién es: "note:12", "rule:rate", "rule:sellers_pay", "expense:5" (el gasto recurrente 5)
 *   date       el día en que se muestra; due_date el día que le tocaba de verdad (distinto si se corrió por un domingo)
 *   type       payment | task | review;  group "daily" = la rutina de todos los días
 *   source     owner | assistant | system
 *   title, details, time, repeat_label, help (por qué importa), text (todo en una frase), link, actions (botones: link,
 *              mark "done" o pay = el gasto recurrente que se paga), lines (el detalle: cada vendedora o agencia y su monto)
 *   status     pending | done | dismissed | overdue (atrasada)
 *   auto       si se tilda sola; mark: quién la marcó (source "auto": se tildó sola), cuándo y con qué nota
 * Las de todos los días que pasaron sin hacerse no se muestran: no se acumulan.
 */
class Calendar
{
    /** Las atrasadas se buscan hasta este número de días atrás (y nunca antes del primer día del calendario). */
    public const OVERDUE_DAYS = 60;

    /** El rango más largo que se pide de una vez (un mes con sus semanas de los bordes). */
    public const MAX_RANGE_DAYS = 62;

    /** Cuánto se guarda lo de hoy (el número de arriba lo pide cada pantalla). */
    public const TODAY_CACHE_SECONDS = 60;

    private const TYPE_ORDER = ['payment' => 0, 'task' => 1, 'review' => 2];

    public function __construct(private CalendarSettings $settings, private ReminderFacts $facts)
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
        foreach ($this->withStatus($this->occurrences($from, $to, $now), $today) as $item) {
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

    /**
     * Lo de hoy, las atrasadas y cuántas quedan, para el calendario, el Dashboard, el número de arriba y el asistente. Se
     * guarda un minuto (el número de arriba lo pide cada pantalla); se borra al tildar, anotar o cambiar los recordatorios.
     * "fresh" lo calcula de nuevo (el calendario, que acaba de cambiar algo).
     */
    public function today(CarbonImmutable $now, bool $fresh = false): array
    {
        $key = self::todayCacheKey($now);
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::TODAY_CACHE_SECONDS, fn () => $this->computeToday($now));
    }

    /** Lo de hoy se vuelve a calcular la próxima vez que se pida. */
    public static function forgetToday(): void
    {
        Cache::forget(self::todayCacheKey(CarbonImmutable::now()));
    }

    private static function todayCacheKey(CarbonImmutable $now): string
    {
        return 'calendar.today.' . $now->toDateString();
    }

    private function computeToday(CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $items = $this->withStatus($this->occurrences($today, $today, $now), $today->toDateString());
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
        $items = array_filter($this->withStatus($this->occurrences($from, $to, $now), $now->toDateString()), fn ($i) => $i['status'] === 'overdue');
        usort($items, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return array_values($items);
    }

    /** Los recordatorios del sistema, con lo que hacen y si están encendidos (la ventana "Recordatorios fijos"). */
    public function settings(): array
    {
        $rules = $this->settings->rules();

        return [
            'rules' => array_map(fn (string $key) => [
                'key' => $key,
                'title' => ReminderRules::RULES[$key]['title'],
                'help' => ReminderRules::RULES[$key]['help'],
                'type' => ReminderRules::RULES[$key]['type'],
                'schedule' => Schedule::label(ReminderRules::pattern($key, $rules)),
                'auto' => ReminderRules::RULES[$key]['auto'],
                'on' => $rules[$key]['on'],
                'weekday' => $rules[$key]['weekday'] ?? null,
            ], array_keys(ReminderRules::RULES)),
            'weekdays' => array_slice(Schedule::WEEKDAYS, 0, 6, true),
        ];
    }

    public function saveSettings(array $rules, User $by, CarbonImmutable $now): array
    {
        $this->settings->saveRules($rules, $by, $now);
        self::forgetToday();

        return $this->settings();
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
        self::forgetToday();
    }

    /** "Deshacer": la aparición vuelve a quedar pendiente (si se había tildado sola, sigue tildada). */
    public function unmark(string $itemKey, string $due): void
    {
        CalendarMark::where('item_key', $itemKey)->whereDate('occurs_on', $due)->delete();
        self::forgetToday();
    }

    /** Anota una nota nueva (del dueño o, más adelante, del asistente). */
    public function createNote(array $input, User $by, string $source = 'owner'): CalendarNote
    {
        $fields = $this->checked($input);
        $note = CalendarNote::create($fields + ['source' => $source, 'created_by' => $by->id, 'updated_by' => $by->id]);
        self::forgetToday();

        return $note;
    }

    public function updateNote(CalendarNote $note, array $input, User $by): CalendarNote
    {
        $note->update($this->checked($input) + ['updated_by' => $by->id]);
        self::forgetToday();

        return $note;
    }

    /** "Borrar" una nota: se archiva y deja de salir en el calendario (lo que se tildó queda guardado). */
    public function archiveNote(CalendarNote $note, User $by): void
    {
        $note->update(['archived_at' => now(), 'archived_by' => $by->id]);
        self::forgetToday();
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
        if (preg_match('/^rule:(\w+)$/', $itemKey, $m) && isset(ReminderRules::RULES[$m[1]])) {
            $rules = $this->settings->rules();

            return $rules[$m[1]]['on'] && $due >= $this->settings->startedOn(CarbonImmutable::now())
                && Schedule::isDue(ReminderRules::pattern($m[1], $rules), $due);
        }
        if (preg_match('/^expense:(\d+)$/', $itemKey, $m)) {
            $recurring = RecurringExpense::where('is_active', true)->find((int) $m[1]);

            return $recurring !== null
                && in_array($due, array_column(app(OtherPayments::class)->dueDates($recurring, $due), 'due_date'), true);
        }

        return false;
    }

    /**
     * Las apariciones que se muestran entre dos días, todavía sin estado: las notas del dueño y los recordatorios del
     * sistema que están encendidos.
     *
     * @return list<array>
     */
    private function occurrences(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $now): array
    {
        return array_merge($this->noteOccurrences($from, $to), $this->ruleOccurrences($from, $to, $now), $this->expenseOccurrences($from, $to, $now));
    }

    /**
     * Los vencimientos de los gastos fijos de Finanzas (paso 3): en su día de pago, o el sábado antes si cae domingo. Desde
     * el primer día del calendario (antes, lo vencido se ve en Finanzas).
     *
     * @return list<array>
     */
    private function expenseOccurrences(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $now): array
    {
        $started = $this->settings->startedOn($now);
        $today = $now->toDateString();
        $items = [];
        foreach ($this->facts->expenses($to->addDay()) as $expense) {
            foreach ($expense['dues'] as $due) {
                $date = Schedule::shift(CarbonImmutable::parse($due['due_date']), $expense['frequency'], 'payment')->toDateString();
                if ($due['due_date'] < $started || $date < $from->toDateString() || $date > $to->toDateString()) {
                    continue;
                }
                $o = ExpenseRules::occurrence($expense['id'], $expense['name'], $expense['category'], $due, $today);
                $items[] = [
                    'item_key' => 'expense:' . $expense['id'],
                    'date' => $date,
                    'due_date' => $due['due_date'],
                    'type' => 'payment',
                    'group' => null,
                    'source' => 'system',
                    'title' => $o['title'],
                    'details' => $o['details'],
                    'time' => null,
                    'repeat_label' => $expense['schedule'],
                    'help' => ExpenseRules::HELP,
                    'base_text' => 'Pago: ' . $o['title'] . '. ' . $o['details'],
                    'accumulates' => true,
                    'auto' => true,
                    'auto_done' => $o['auto_done'],
                    'auto_note' => $o['auto_note'],
                    'link' => '/finanzas?tab=recurring',
                    'actions' => $o['actions'],
                    'lines' => [],
                    'note_id' => null,
                    'note_fields' => null,
                ];
            }
        }

        return $items;
    }

    /** @return list<array> */
    private function noteOccurrences(CarbonImmutable $from, CarbonImmutable $to): array
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
                    'group' => null,
                    'source' => $note->source,
                    'title' => $fields['title'],
                    'details' => $fields['details'],
                    'time' => $fields['time'],
                    'repeat_label' => Schedule::label($pattern),
                    'help' => null,
                    'base_text' => NoteRules::text($fields),
                    'accumulates' => true,
                    'auto' => false,
                    'auto_done' => false,
                    'auto_note' => null,
                    'link' => null,
                    'actions' => [],
                    'lines' => [],
                    'note_id' => $note->id,
                    'note_fields' => $fields,
                ];
            }
        }

        return $items;
    }

    /** @return list<array> */
    private function ruleOccurrences(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $now): array
    {
        $rules = $this->settings->rules();
        $today = $now->toDateString();
        // Los recordatorios del sistema existen desde que existe el calendario: antes no hay nada que hacer ni atrasado
        $started = $this->settings->startedOn($now);
        $facts = $this->facts->forRange($from->subDay(), $to->addDay());
        // Lo de ahora (conciliación, por aprobar, inventario) solo hace falta si hoy está en el rango
        if ($today >= $from->toDateString() && $today <= $to->toDateString()) {
            $facts += $this->facts->now($now);
        }

        // Las fechas de cada recordatorio encendido; los pagos de la semana piden los datos de su semana
        $dates = [];
        $weeks = ['sellers_pay' => [], 'agencies_pay' => []];
        foreach (ReminderRules::RULES as $key => $rule) {
            if (!$rules[$key]['on']) {
                continue;
            }
            $dates[$key] = array_values(array_filter(Schedule::dates(ReminderRules::pattern($key, $rules), $from, $to), fn ($d) => $d['due'] >= $started));
            if (isset($weeks[$key])) {
                foreach ($dates[$key] as $d) {
                    $weeks[$key][] = ReminderRules::lastWeek(CarbonImmutable::parse($d['due']))[0]->toDateString();
                }
            }
        }
        if ($weeks['sellers_pay'] !== [] || $weeks['agencies_pay'] !== []) {
            $facts += $this->facts->weeks($weeks['sellers_pay'], $weeks['agencies_pay']);
        }

        $items = [];
        foreach ($dates as $key => $list) {
            $rule = ReminderRules::RULES[$key];
            $pattern = ReminderRules::pattern($key, $rules);
            foreach ($list as $d) {
                $o = ReminderRules::occurrence($key, $d['due'], $today, $facts);
                $items[] = [
                    'item_key' => "rule:{$key}",
                    'date' => $d['date'],
                    'due_date' => $d['due'],
                    'type' => $rule['type'],
                    'group' => $rule['group'],
                    'source' => 'system',
                    'title' => $o['title'],
                    'details' => $o['details'],
                    'time' => null,
                    'repeat_label' => Schedule::label($pattern),
                    'help' => $rule['help'],
                    'base_text' => Schedule::TYPES[$rule['type']] . ': ' . $o['title'] . '. ' . $o['details'],
                    'accumulates' => $rule['accumulates'],
                    'auto' => $rule['auto'],
                    'auto_done' => $o['auto_done'],
                    'auto_note' => $o['auto_note'],
                    'link' => $o['link'],
                    'actions' => $o['actions'],
                    'lines' => $o['lines'],
                    'note_id' => null,
                    'note_fields' => null,
                ];
            }
        }

        return $items;
    }

    /**
     * Les suma a las apariciones cómo están (hecha, descartada, pendiente, atrasada) y su frase, y las ordena. Lo que se
     * tildó solo cuenta como hecho; lo de todos los días que pasó sin hacerse no se muestra.
     */
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

        $out = [];
        foreach ($items as $item) {
            $mark = $marks->get($item['item_key'] . '|' . $item['due_date']);
            $status = $mark?->status ?? ($item['auto_done'] ? 'done' : null);
            $item['status'] = Schedule::status($status, $item['date'], $today, $item['accumulates']);
            if ($item['status'] === 'missed') {
                continue;
            }
            $item['text'] = Schedule::sentence($item['base_text'], $item['date'], $item['due_date'], $item['status']);
            $item['mark'] = $mark ? [
                'status' => $mark->status,
                'note' => $mark->note,
                'source' => $mark->source,
                'by' => $mark->marker ? PersonName::of($mark->marker->names, $mark->marker->surnames) : null,
                'at' => $mark->updated_at?->format('Y-m-d H:i'),
            ] : ($item['auto_done'] ? ['status' => 'done', 'note' => $item['auto_note'], 'source' => 'auto', 'by' => null, 'at' => null] : null);
            unset($item['base_text'], $item['auto_done'], $item['auto_note']);
            $out[] = $item;
        }

        // Primero lo pendiente; después lo propio del día antes que la rutina, por hora (sin hora al final), tipo y título
        usort($out, fn ($a, $b) => [self::closed($a), $a['group'] === 'daily' ? 1 : 0, $a['time'] ?? '99:99', self::TYPE_ORDER[$a['type']] ?? 9, $a['title']]
            <=> [self::closed($b), $b['group'] === 'daily' ? 1 : 0, $b['time'] ?? '99:99', self::TYPE_ORDER[$b['type']] ?? 9, $b['title']]);

        return $out;
    }

    private static function closed(array $item): int
    {
        return in_array($item['status'], ['done', 'dismissed'], true) ? 1 : 0;
    }
}
