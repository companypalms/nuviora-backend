<?php

namespace App\Services\Calendar;

use App\Models\CalendarDay;
use App\Models\CalendarItemPlan;
use App\Models\CalendarMark;
use App\Models\CalendarNote;
use App\Models\CalendarTimer;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Services\Calendar\Readers\ReminderFacts;
use App\Services\Calendar\Rules\DayPlan;
use App\Services\Calendar\Rules\ExpenseRules;
use App\Services\Calendar\Rules\NoteRules;
use App\Services\Finance\OtherPayments;
use App\Services\Calendar\Rules\ReminderRules;
use App\Services\Calendar\Rules\Schedule;
use App\Services\Calendar\Rules\TimerRules;
use App\Services\Metrics\PersonName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
 *   priority   alta | normal | baja; minutes cuánto toma; rank el lugar que le puso el dueño arrastrando (.107, DayPlan)
 * Lo de hoy trae además (.108) el reloj de cada tarea (timers), cuánto sueles tardar (usual), lo hecho a tiempo y la racha
 * (score), y la hora del servidor (server_now) para que el reloj de la pantalla no se adelante ni se atrase.
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

    /** Primero lo propio del día, después Meta Ads y los editores (.67) y al final la rutina de todos los días. */
    private const GROUP_ORDER = ['meta' => 1, 'daily' => 2];

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
        // .107: cada día en el orden en que se hace (lo atrasado sale en el de hoy, con today())
        $plans = [];
        foreach ($days as $date => $items) {
            $plans[$date] = DayPlan::order(self::pendingOf($items), [], $date === $today ? $this->clock($now) : null);
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'today' => $today,
            'started_on' => $this->settings->startedOn($now),
            'days' => $days,
            'plans' => $plans,
            'zone' => $this->settings->zone(),
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

        // .108: la hora del servidor va aparte de lo guardado (el reloj de la pantalla la usa para no correrse)
        return Cache::remember($key, self::TODAY_CACHE_SECONDS, fn () => $this->computeToday($now)) + ['server_now' => $now->toIso8601String()];
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
        [$overdue, $resolved] = $this->pastOpen($now);
        $open = self::pendingOf($items);
        $pending = count($open);
        $done = count(array_filter($items, fn ($i) => $i['status'] === 'done'));
        // .108: el reloj de cada tarea; la que se dejó "Más tarde" va al final de su etapa
        $timers = $this->timersOf(array_merge($items, $overdue, $resolved));
        $later = fn (array $i) => $i + ['later' => $timers[self::planKeyOf($i)]['later_at'] ?? null];
        $score = $this->score($items, $overdue, $pending, $now);

        return [
            'date' => $today->toDateString(),
            'label' => ucfirst(Schedule::dayName($today->toDateString())),
            'items' => $items,
            'overdue' => $overdue,
            'counts' => ['pending' => $pending, 'overdue' => count($overdue), 'done' => $done],
            'text' => self::summary($pending, count($overdue)),
            // .107 "Tu día": en qué orden se hace (por etapas) y cuántos minutos de trabajo faltan
            // .108: "ya es la hora" en la hora del dueño (sus notas tienen la hora de su zona)
            'plan' => DayPlan::order(array_map($later, $open), array_map($later, $overdue), $this->clock($now)),
            'minutes_left' => array_sum(array_column($open, 'minutes')) + array_sum(array_column($overdue, 'minutes')),
            // .108: lo atrasado que se resolvió hoy (cuenta en lo hecho de hoy y en su barra)
            'resolved' => $resolved,
            'timers' => $timers,
            'usual' => $this->usual(array_column(array_merge($items, $overdue), 'item_key')),
            'score' => $score,
            'zone' => $this->settings->zone(),
        ];
    }

    /** Lo que quedó sin hacer en días anteriores y se acumula, de lo más viejo a lo más nuevo. */
    public function overdue(CarbonImmutable $now): array
    {
        return $this->pastOpen($now)[0];
    }

    /**
     * Lo atrasado (como overdue()) y, .108, lo atrasado que se hizo o descartó hoy (para que cuente en lo hecho de hoy).
     *
     * @return array{0: list<array>, 1: list<array>}
     */
    private function pastOpen(CarbonImmutable $now): array
    {
        $started = CarbonImmutable::parse($this->settings->startedOn($now));
        $from = $now->startOfDay()->subDays(self::OVERDUE_DAYS)->max($started);
        $to = $now->startOfDay()->subDay();
        if ($from->gt($to)) {
            return [[], []];
        }
        $today = $now->toDateString();
        $items = $this->withStatus($this->occurrences($from, $to, $now), $today);
        $overdue = array_filter($items, fn ($i) => $i['status'] === 'overdue');
        usort($overdue, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $resolved = array_filter($items, fn ($i) => $i['accumulates'] && in_array($i['status'], ['done', 'dismissed'], true)
            && $i['mark'] !== null && $i['mark']['source'] !== 'auto' && str_starts_with((string) $i['mark']['at'], $today));

        return [array_values($overdue), array_values($resolved)];
    }

    /** Los recordatorios del sistema, con lo que hacen y si están encendidos (la ventana "Recordatorios fijos"). */
    public function settings(): array
    {
        $rules = $this->settings->rules();
        $plans = $this->plans(array_merge(array_map(fn ($k) => "rule:{$k}", array_keys(ReminderRules::RULES)), [DayPlan::ALL_EXPENSES]));
        $plan = fn (string $itemKey) => array_intersect_key(DayPlan::resolve($itemKey, $plans[$itemKey] ?? null), ['priority' => 0, 'minutes' => 0]);

        return [
            'rules' => array_map(fn (string $key) => $plan("rule:{$key}") + [
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
            // .107: los minutos y la prioridad de los gastos fijos que no tienen los suyos
            'expenses' => $plan(DayPlan::ALL_EXPENSES),
            // .108: "Tu hora" y las que se pueden elegir
            'timezone' => $this->settings->zone() + ['zones' => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys(CalendarSettings::ZONES), CalendarSettings::ZONES)],
        ];
    }

    /** .108 "Tu hora": la zona en que el dueño ve y anota las horas del calendario (todo lo demás sigue en la del negocio). */
    public function saveTimezone(string $zone, User $by, CarbonImmutable $now): void
    {
        $this->settings->saveTimezone($zone, $by, $now);
        self::forgetToday();
    }

    /** La hora de ahora ("13:05") en la zona del dueño. */
    private function clock(CarbonImmutable $now): string
    {
        return $now->setTimezone($this->settings->timezone())->format('H:i');
    }

    /**
     * .107: guarda la prioridad o los minutos de una o varias cosas, para todos los días ("Alta · 5 min" en Tu día, o la
     * ventana de los recordatorios fijos). Cambiar la prioridad la pone al final de su nuevo grupo (sin lugar propio).
     *
     * @param list<array{item_key: string, priority?: ?string, minutes?: ?int}> $items
     */
    public function savePlan(array $items, User $by): void
    {
        $problems = [];
        foreach (array_values($items) as $i => $row) {
            if (!$this->knownKey((string) ($row['item_key'] ?? ''))) {
                $problems["items.{$i}.item_key"] = 'Eso ya no está en el calendario. Actualiza la página.';
            }
            $problems += DayPlan::problems($row['priority'] ?? null, $row['minutes'] ?? null, "items.{$i}.");
        }
        if ($problems) {
            throw ValidationException::withMessages($problems);
        }
        $saved = $this->plans(array_column($items, 'item_key'));
        $all = $this->plans([DayPlan::ALL_EXPENSES])[DayPlan::ALL_EXPENSES] ?? null;
        foreach ($items as $row) {
            $key = $row['item_key'];
            $current = DayPlan::resolve($key, $saved[$key] ?? null, $key === DayPlan::ALL_EXPENSES ? null : $all);
            $priority = $row['priority'] ?? $current['priority'];
            $minutes = (int) ($row['minutes'] ?? $current['minutes']);
            CalendarItemPlan::updateOrCreate(['item_key' => $key], [
                'priority' => $priority,
                'minutes' => $minutes,
                'rank' => $priority === $current['priority'] ? $current['rank'] : null,
                'updated_by' => $by->id,
            ]);
            if ($key === DayPlan::ALL_EXPENSES) {
                $all = ['priority' => $priority, 'minutes' => $minutes];
            }
        }
        self::forgetToday();
    }

    /**
     * .107: el orden que puso el dueño arrastrando, para todos los días. $itemKeys es el grupo de esa prioridad entero, de
     * arriba hacia abajo; la que llegó de otro grupo toma esta prioridad.
     */
    public function reorder(string $priority, array $itemKeys, User $by): void
    {
        $problems = DayPlan::problems($priority, null);
        foreach (array_values($itemKeys) as $i => $key) {
            if (!is_string($key) || $key === DayPlan::ALL_EXPENSES || !$this->knownKey($key)) {
                $problems["item_keys.{$i}"] = 'Eso ya no está en el calendario. Actualiza la página.';
            }
        }
        if ($problems) {
            throw ValidationException::withMessages($problems);
        }
        $saved = $this->plans($itemKeys);
        $all = $this->plans([DayPlan::ALL_EXPENSES])[DayPlan::ALL_EXPENSES] ?? null;
        foreach (array_values(array_unique($itemKeys)) as $i => $key) {
            CalendarItemPlan::updateOrCreate(['item_key' => $key], [
                'priority' => $priority,
                'minutes' => DayPlan::resolve($key, $saved[$key] ?? null, $all)['minutes'],
                'rank' => $i + 1,
                'updated_by' => $by->id,
            ]);
        }
        self::forgetToday();
    }

    /** .107 "Volver al orden automático": se olvida el orden arrastrado (las prioridades y los minutos quedan). */
    public function resetOrder(): void
    {
        CalendarItemPlan::whereNotNull('rank')->update(['rank' => null]);
        self::forgetToday();
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
        DB::transaction(function () use ($itemKey, $due, $status, $note, $source, $by) {
            CalendarMark::updateOrCreate(
                ['item_key' => $itemKey, 'occurs_on' => $due],
                ['status' => $status, 'note' => $note, 'source' => $source, 'marked_by' => $by->id],
            );
            // .108: al hacerla, su reloj termina (y queda si fue a tiempo); al descartarla, se pausa
            if ($timer = $this->timerOf($itemKey, $due)) {
                $now = CarbonImmutable::now();
                $elapsed = TimerRules::elapsed($timer->spent_seconds, self::immutable($timer->started_at), $now);
                $timer->fill(['spent_seconds' => $elapsed, 'started_at' => null]);
                if ($status === 'done') {
                    $timer->fill(['finished_at' => $now, 'on_time' => TimerRules::onTime($elapsed, $timer->minutes)]);
                }
                $timer->save();
            }
        });
        self::forgetToday();
    }

    /** "Deshacer": la aparición vuelve a quedar pendiente (si se había tildado sola, sigue tildada). */
    public function unmark(string $itemKey, string $due): void
    {
        CalendarMark::where('item_key', $itemKey)->whereDate('occurs_on', $due)->delete();
        // .108: si tenía reloj, vuelve a quedar en pausa con lo que corrió
        $this->timerOf($itemKey, $due)?->update(['finished_at' => null, 'on_time' => null]);
        self::forgetToday();
    }

    /**
     * .108: el reloj de una tarea de hoy (o atrasada): "start" (empezar o seguir; el que corría se pausa: uno a la vez),
     * "pause", "extend" (+5 min) y "later" ("Más tarde": se pausa y va al final de su etapa). "Hecho" es mark().
     */
    public function timer(string $action, string $itemKey, string $due, User $by, CarbonImmutable $now): void
    {
        if ($due > $now->toDateString() || !$this->exists($itemKey, $due)) {
            throw ValidationException::withMessages(['item_key' => 'Eso no está para hoy en el calendario. Actualiza la página.']);
        }
        $timer = $this->timerOf($itemKey, $due);
        if ($timer?->finished_at !== null && $timer !== null) {
            throw ValidationException::withMessages(['item_key' => 'Esa ya está hecha. Si quieres seguirla, primero toca "Deshacer".']);
        }
        DB::transaction(function () use ($action, $itemKey, $due, $by, $now, $timer) {
            $timer ??= new CalendarTimer(['item_key' => $itemKey, 'occurs_on' => $due, 'minutes' => $this->minutesOf($itemKey), 'user_id' => $by->id]);
            if ($action === 'start') {
                CalendarTimer::whereNotNull('started_at')->whereNull('finished_at')->when($timer->exists, fn ($q) => $q->whereKeyNot($timer->getKey()))
                    ->get()->each(fn (CalendarTimer $other) => self::pause($other, $now)->save());
                $timer->fill(['started_at' => $timer->started_at ?? $now, 'later_at' => null]);
            } elseif ($action === 'pause') {
                self::pause($timer, $now);
            } elseif ($action === 'extend') {
                $timer->extra_minutes = min(TimerRules::MAX_EXTRA, (int) $timer->extra_minutes + TimerRules::EXTEND);
            } else {
                self::pause($timer, $now)->later_at = $now;
            }
            $timer->save();
        });
        self::forgetToday();
    }

    private static function pause(CalendarTimer $timer, CarbonImmutable $now): CalendarTimer
    {
        if ($timer->started_at !== null) {
            $timer->fill(['spent_seconds' => TimerRules::elapsed((int) $timer->spent_seconds, self::immutable($timer->started_at), $now), 'started_at' => null]);
        }

        return $timer;
    }

    /** Anota una nota nueva (del dueño o, más adelante, del asistente). */
    public function createNote(array $input, User $by, string $source = 'owner'): CalendarNote
    {
        $fields = $this->checked($input);
        $plan = $this->checkedPlan($input);
        $note = CalendarNote::create($fields + ['source' => $source, 'created_by' => $by->id, 'updated_by' => $by->id]);
        CalendarItemPlan::updateOrCreate(['item_key' => $note->itemKey()], $plan + DayPlan::NOTE_DEFAULT + ['updated_by' => $by->id]);
        self::forgetToday();

        return $note;
    }

    public function updateNote(CalendarNote $note, array $input, User $by): CalendarNote
    {
        $fields = $this->checked($input);
        $plan = $this->checkedPlan($input);
        $note->update($fields + ['updated_by' => $by->id]);
        if ($plan !== []) {
            $this->savePlan([['item_key' => $note->itemKey()] + $plan], $by);
        }
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

    private function timerOf(string $itemKey, string $due): ?CalendarTimer
    {
        return CalendarTimer::where('item_key', $itemKey)->whereDate('occurs_on', $due)->first();
    }

    /** Los minutos que toma una cosa ahora (los del dueño o los de siempre): con eso empieza su reloj. */
    private function minutesOf(string $itemKey): int
    {
        $plans = $this->plans([$itemKey, DayPlan::ALL_EXPENSES]);

        return DayPlan::resolve($itemKey, $plans[$itemKey] ?? null, $plans[DayPlan::ALL_EXPENSES] ?? null)['minutes'];
    }

    /**
     * .108: el reloj de cada cosa de la lista, por "item_key|due_date": sus minutos, los "+5", lo que corrió, desde cuándo
     * corre (null: en pausa), cuándo se acaba, si se dejó para después y si se hizo a tiempo.
     */
    private function timersOf(array $items): array
    {
        if ($items === []) {
            return [];
        }
        $wanted = array_flip(array_map([self::class, 'planKeyOf'], $items));
        $out = [];
        $rows = CalendarTimer::whereIn('item_key', array_unique(array_column($items, 'item_key')))
            ->whereIn('occurs_on', array_unique(array_column($items, 'due_date')))->get();
        foreach ($rows as $t) {
            $key = $t->item_key . '|' . $t->occurs_on->toDateString();
            if (!isset($wanted[$key])) {
                continue;
            }
            $started = self::immutable($t->started_at);
            $out[$key] = [
                'minutes' => $t->minutes,
                'extra_minutes' => $t->extra_minutes,
                'spent_seconds' => $t->spent_seconds,
                'started_at' => $started?->toIso8601String(),
                'ends_at' => TimerRules::endsAt($t->minutes, $t->extra_minutes, $t->spent_seconds, $started)?->toIso8601String(),
                'later_at' => $t->later_at?->toIso8601String(),
                'finished' => $t->finished_at !== null,
                'on_time' => $t->on_time,
            ];
        }

        return $out;
    }

    /** .108: cuánto sueles tardar en cada cosa (desde la 3.ª vez con el reloj), en minutos. */
    private function usual(array $itemKeys): array
    {
        if ($itemKeys === []) {
            return [];
        }
        $seconds = [];
        CalendarTimer::whereIn('item_key', array_values(array_unique($itemKeys)))->whereNotNull('finished_at')
            ->orderByDesc('finished_at')->limit(1000)->get(['item_key', 'spent_seconds'])
            ->each(function (CalendarTimer $t) use (&$seconds) { $seconds[$t->item_key][] = $t->spent_seconds; });

        return array_filter(array_map([TimerRules::class, 'usual'], $seconds), fn ($m) => $m !== null);
    }

    /**
     * .108: lo de hoy en números de verdad (hechas a tiempo con el reloj y la racha), y el resumen del día guardado (de ahí
     * sale la racha de mañana). El día queda completo cuando no falta nada, ni de hoy ni atrasado.
     */
    private function score(array $items, array $overdue, int $pending, CarbonImmutable $now): array
    {
        $today = $now->toDateString();
        $finished = CalendarTimer::whereBetween('finished_at', [$now->startOfDay(), $now->endOfDay()])->get(['on_time']);
        $onTime = $finished->where('on_time', true)->count();
        $allDone = $items !== [] && $pending === 0 && $overdue === [];
        $day = CalendarDay::whereDate('date', $today)->first() ?? new CalendarDay(['date' => $today]);
        $day->fill([
            'total' => count($items),
            'done' => count(array_filter($items, fn ($i) => $i['status'] === 'done')),
            'timed' => $finished->count(),
            'on_time' => $onTime,
            'all_done_at' => $allDone ? ($day->all_done_at ?? $now) : null,
        ])->save();

        $days = CalendarDay::where('date', '>=', $now->subDays(400)->toDateString())->get(['date', 'all_done_at'])
            ->mapWithKeys(fn (CalendarDay $d) => [$d->date->toDateString() => $d->all_done_at !== null])->all();

        return ['on_time' => $onTime, 'timed' => $finished->count(), 'streak' => TimerRules::streak($days, $today), 'best' => TimerRules::best($days)];
    }

    private static function planKeyOf(array $item): string
    {
        return $item['item_key'] . '|' . $item['due_date'];
    }

    private static function immutable(mixed $date): ?CarbonImmutable
    {
        return $date ? CarbonImmutable::parse($date) : null;
    }

    /** .107: la prioridad y los minutos que llegaron con una nota (solo los que vinieron), revisados. */
    private function checkedPlan(array $input): array
    {
        $plan = array_filter([
            'priority' => $input['priority'] ?? null,
            'minutes' => ($input['minutes'] ?? '') !== '' ? $input['minutes'] : null,
        ], fn ($v) => $v !== null);
        if ($problems = DayPlan::problems($plan['priority'] ?? null, $plan['minutes'] ?? null)) {
            throw ValidationException::withMessages($problems);
        }
        if (isset($plan['minutes'])) {
            $plan['minutes'] = (int) $plan['minutes'];
        }

        return $plan;
    }

    /** .107: si esa cosa existe en el calendario (sin importar el día), para guardarle su prioridad y sus minutos. */
    private function knownKey(string $itemKey): bool
    {
        if ($itemKey === DayPlan::ALL_EXPENSES) {
            return true;
        }
        if (preg_match('/^note:(\d+)$/', $itemKey, $m)) {
            return CalendarNote::active()->whereKey((int) $m[1])->exists();
        }
        if (preg_match('/^rule:(\w+)$/', $itemKey, $m)) {
            return isset(ReminderRules::RULES[$m[1]]);
        }
        if (preg_match('/^expense:(\d+)$/', $itemKey, $m)) {
            return RecurringExpense::whereKey((int) $m[1])->exists();
        }

        return false;
    }

    /** @return array<string, array{priority: string, minutes: int, rank: ?int}> lo que guardó el dueño, por cosa */
    private function plans(array $itemKeys): array
    {
        if ($itemKeys === []) {
            return [];
        }

        return CalendarItemPlan::whereIn('item_key', array_values(array_unique($itemKeys)))->get(['item_key', 'priority', 'minutes', 'rank'])
            ->mapWithKeys(fn (CalendarItemPlan $p) => [$p->item_key => ['priority' => $p->priority, 'minutes' => $p->minutes, 'rank' => $p->rank]])
            ->all();
    }

    /** .107: a cada cosa, su prioridad, sus minutos y su lugar (lo del dueño, o lo de siempre). */
    private function withPlan(array $items): array
    {
        $plans = $this->plans(array_merge(array_column($items, 'item_key'), [DayPlan::ALL_EXPENSES]));
        $all = $plans[DayPlan::ALL_EXPENSES] ?? null;

        return array_map(fn (array $item) => $item + DayPlan::resolve($item['item_key'], $plans[$item['item_key']] ?? null, $all), $items);
    }

    /** Lo que queda por hacer de una lista (pendiente), en su orden. */
    private static function pendingOf(array $items): array
    {
        return array_values(array_filter($items, fn ($i) => $i['status'] === 'pending'));
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
                    // .129 (dueño: "no se acumula"): la de todos los días es como las tareas de todos los días: la de hoy reemplaza a la de ayer
                    'accumulates' => $fields['repeat'] !== 'daily',
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
        // .67: el trabajo de los editores (la semana que se revisa) y lo que aprendimos (los 7 días antes), si ya empezó
        $editorWeeks = array_values(array_filter(array_map(fn ($d) => ReminderRules::lastWeek(CarbonImmutable::parse($d['due']))[0]->toDateString(), $dates['editors_week'] ?? []),
            fn ($monday) => $monday <= $today));
        $learningDays = array_values(array_filter(array_column($dates['learnings_week'] ?? [], 'due'), fn ($due) => $due <= $today));
        if ($editorWeeks !== [] || $learningDays !== []) {
            $facts += $this->facts->metaWeeks($editorWeeks, $learningDays);
        }
        // .108: las horas de lo que pasó (entregó, aprobó…) se dicen en la hora del dueño
        $facts += ['zone' => $this->settings->timezone(), 'business_zone' => CalendarSettings::BUSINESS_ZONE];

        $items = [];
        foreach ($dates as $key => $list) {
            $rule = ReminderRules::RULES[$key];
            $pattern = ReminderRules::pattern($key, $rules);
            foreach ($list as $d) {
                $o = ReminderRules::occurrence($key, $d['due'], $today, $facts);
                if ($o['hidden'] ?? false) {
                    continue;
                }
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
        $items = $this->withPlan($items);
        $zone = $this->settings->timezone();
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
                // .108: la misma, en la hora del dueño (lo que se ve)
                'at_zone' => $mark->updated_at?->copy()->setTimezone($zone)->format('Y-m-d H:i'),
            ] : ($item['auto_done'] ? ['status' => 'done', 'note' => $item['auto_note'], 'source' => 'auto', 'by' => null, 'at' => null, 'at_zone' => null] : null);
            unset($item['base_text'], $item['auto_done'], $item['auto_note']);
            $out[] = $item;
        }

        // Primero lo pendiente; después lo propio del día, Meta Ads y la rutina, por hora (sin hora al final), tipo y título
        usort($out, fn ($a, $b) => [self::closed($a), self::GROUP_ORDER[$a['group']] ?? 0, $a['time'] ?? '99:99', self::metaOrder($a), self::TYPE_ORDER[$a['type']] ?? 9, $a['title']]
            <=> [self::closed($b), self::GROUP_ORDER[$b['group']] ?? 0, $b['time'] ?? '99:99', self::metaOrder($b), self::TYPE_ORDER[$b['type']] ?? 9, $b['title']]);

        return $out;
    }

    /** .67: el bloque de Meta Ads va en el orden del trabajo (análisis, aprobar, entregas, al día, banca), no por tipo. */
    private static function metaOrder(array $item): int
    {
        return $item['group'] === 'meta' ? (int) array_search(substr($item['item_key'], 5), array_keys(ReminderRules::RULES), true) : 0;
    }

    private static function closed(array $item): int
    {
        return in_array($item['status'], ['done', 'dismissed'], true) ? 1 : 0;
    }
}
