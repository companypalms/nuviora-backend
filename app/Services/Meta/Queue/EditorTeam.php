<?php

namespace App\Services\Meta\Queue;

use App\Models\CreativeBatch;
use App\Models\EditorDayOff;
use App\Models\EditorSchedule;
use App\Models\User;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Products\ProductModes;
use App\Services\Meta\Products\ProductProfiles;
use App\Services\Meta\Products\ProductQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `.175`: el equipo de editores con su horario, su meta y cómo va cada uno hoy (lo que lee y guarda; las reglas, en
 * EditorPaceRules y AdvanceRules). Para el líder: su franja del equipo, la misión de asignarle otro al que quedó libre y lo
 * que se puede adelantar. Para el dueño: los horarios y "¿Alcanza el equipo?" contra la meta.
 */
final class EditorTeam
{
    /** Cuántos días se miran para "cómo va el equipo contra la meta". */
    public const PACE_DAYS = 14;

    /** La meta mínima de batches por editor por día (el ajuste "Batches por editor por día"). */
    public static function goal(): int
    {
        return max(1, (int) round((float) (new DecisionSettings())->get()['batches_per_editor']));
    }

    /** @return array<int, array{days: list<int>, start: string, end: string}> */
    public static function schedules(array $ids): array
    {
        return EditorSchedule::whereIn('user_id', $ids ?: [0])->get()->mapWithKeys(fn (EditorSchedule $s) => [(int) $s->user_id => $s->rule()])->all();
    }

    /** @return array<int, list<string>> los días de permiso de cada uno entre dos fechas */
    public static function daysOff(array $ids, string $from, string $to): array
    {
        $out = [];
        foreach (EditorDayOff::whereIn('user_id', $ids ?: [0])->whereBetween('date', [$from, $to])->get(['user_id', 'date']) as $d) {
            $out[(int) $d->user_id][] = substr((string) $d->date, 0, 10);
        }

        return $out;
    }

    /**
     * Los batches que cada uno entregó por primera vez (la V01), por día.
     *
     * @return array<int, array<string, int>>
     */
    public static function firstDeliveries(array $ids, string $from, string $to): array
    {
        $out = [];
        $rows = DB::table('creative_batch_deliveries')->where('version', 1)->whereIn('editor_id', $ids ?: [0])
            ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])->get(['editor_id', 'created_at']);
        foreach ($rows as $r) {
            $day = substr((string) $r->created_at, 0, 10);
            $out[(int) $r->editor_id][$day] = ($out[(int) $r->editor_id][$day] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * Cómo está hoy cada editor: su horario, si está trabajando, cuánto le queda, lo que entregó por primera vez hoy contra
     * la meta, lo que tiene por entregar y si está libre (desde cuándo).
     *
     * @return list<array{id: int, name: string, leader: bool, schedule: ?array, schedule_text: ?string, state: string, left: ?int, start: ?string, end: ?string,
     *     done: int, goal: int, open: int, free: bool, free_since: ?string}>
     */
    public static function today(): array
    {
        $editors = EditorWork::editors();
        if ($editors === []) {
            return [];
        }
        $ids = array_column($editors, 'id');
        $now = now(config('app.timezone'))->format('Y-m-d H:i:s');
        $date = substr($now, 0, 10);
        $schedules = self::schedules($ids);
        $off = self::daysOff($ids, $date, $date);
        $done = self::firstDeliveries($ids, $date, $date);
        // .99: lo de un producto en pausa, en liquidación o abandonado está en espera: no es carga de nadie
        // .95: un batch ya hecho que el líder está cargando a la banca no es un encargo
        $open = ProductModes::withoutHeld(CreativeBatch::whereIn('status', ['approved', 'production'])->whereIn('editor_id', $ids))
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'imported'))
            ->selectRaw('editor_id, count(*) as n')->groupBy('editor_id')->pluck('n', 'editor_id');
        $last = DB::table('creative_batch_deliveries')->whereIn('editor_id', $ids)->where('created_at', '>=', "{$date} 00:00:00")
            ->selectRaw('editor_id, max(created_at) as at')->groupBy('editor_id')->pluck('at', 'editor_id');
        $goal = self::goal();

        return array_map(function ($e) use ($schedules, $off, $done, $open, $last, $now, $date, $goal) {
            $s = $schedules[$e['id']] ?? null;
            $st = EditorPaceRules::now($s, $off[$e['id']] ?? [], $now);
            $n = (int) ($open[$e['id']] ?? 0);
            $free = EditorPaceRules::free($st['state'], $n);

            return $e + ['schedule' => $s, 'schedule_text' => EditorPaceRules::describe($s)] + $st + [
                'done' => (int) ($done[$e['id']][$date] ?? 0), 'goal' => $goal, 'open' => $n, 'free' => $free,
                'free_since' => $free ? EditorPaceRules::freeSince($s, $now, isset($last[$e['id']]) ? (string) $last[$e['id']] : null) : null,
            ];
        }, $editors);
    }

    /** Cuántos están libres ahora (para el número del menú del líder). */
    public static function freeCount(): int
    {
        return count(array_filter(self::today(), fn ($e) => $e['free']));
    }

    /**
     * Lo que puede hacer el equipo por día si cada uno cumple su meta, y a quién le falta el horario.
     *
     * @return array{per_day: float, people: int, goal: int, missing: list<string>}
     */
    public static function capacity(): array
    {
        $editors = EditorWork::editors();
        $schedules = self::schedules(array_column($editors, 'id'));
        $goal = self::goal();

        return ['per_day' => EditorPaceRules::capacity(array_map(fn ($e) => $schedules[$e['id']] ?? null, $editors), $goal), 'people' => count($editors), 'goal' => $goal,
            'missing' => array_values(array_column(array_filter($editors, fn ($e) => !isset($schedules[$e['id']])), 'name'))];
    }

    /**
     * Cómo va el equipo contra la meta en los últimos 14 días (sin hoy).
     *
     * @return array{done: int, goal: int, pct: ?float, from: string, to: string}|null
     */
    public static function pace(): ?array
    {
        $editors = EditorWork::editors();
        if ($editors === []) {
            return null;
        }
        $ids = array_column($editors, 'id');
        $to = now(config('app.timezone'))->subDay()->toDateString();
        $from = now(config('app.timezone'))->subDays(self::PACE_DAYS)->toDateString();
        $schedules = self::schedules($ids);
        $off = self::daysOff($ids, $from, $to);
        $done = self::firstDeliveries($ids, $from, $to);
        $rows = array_map(fn ($id) => ['schedule' => $schedules[$id] ?? null, 'off' => $off[$id] ?? [], 'done' => (int) array_sum($done[$id] ?? [])], $ids);

        return EditorPaceRules::teamPace($rows, self::goal(), $from, $to) + ['from' => $from, 'to' => $to];
    }

    /**
     * Lo que el líder puede adelantar (AdvanceRules): de cada producto que vende con la ficha completa, lo que le falta para
     * las próximas 2 semanas; y los productos nuevos de la cola a los que no les falta nada.
     *
     * @return list<array>
     */
    public static function advance(): array
    {
        $today = now(config('app.timezone'))->toDateString();
        $rates = (new EditorForecast())->rates();
        $ids = ProductProfiles::completeIds(array_keys($rates));
        $names = DB::table('products')->whereIn('id', $ids ?: [0])->get(['id', 'title', 'showable_name'])
            ->mapWithKeys(fn ($p) => [(int) $p->id => (string) ($p->showable_name ?: $p->title)]);
        $open = CreativeBatch::whereIn('product_id', $ids ?: [0])->whereIn('status', DemandRules::OPEN)
            ->selectRaw('product_id, count(*) as n')->groupBy('product_id')->pluck('n', 'product_id');
        $selling = array_map(fn ($pid) => ['product_id' => $pid, 'product' => $names[$pid] ?? "Producto {$pid}", 'rate' => (float) $rates[$pid],
            'open' => (int) ($open[$pid] ?? 0)], $ids);
        $batches = (int) (new DecisionSettings())->get()['start_batches'];
        $queued = array_map(fn ($q) => ['product_id' => (int) $q['product_id'], 'product' => $q['product'], 'position' => (int) $q['position'], 'batches' => $batches],
            array_values(array_filter(ProductQueue::rows(), fn ($q) => $q['missing'] === [])));

        // Lo que hoy ya se intentó y no se pudo (sin ciudad con stock o sin ángulo) lo dice, sin botón
        return array_map(fn ($it) => $it + ['blocked' => $it['kind'] === 'next' ? QueueProposer::blocked($it['product_id']) : null],
            AdvanceRules::items($selling, $queued, $today));
    }

    /**
     * Los editores con su horario, para quien los mira: el dueño ve y cambia todos; el líder, todos, y cambia los de sus
     * editores de video.
     *
     * @return list<array{id: int, name: string, leader: bool, schedule: ?array, schedule_text: ?string, days_off: list<array{date: string, note: ?string}>, can_edit: bool}>
     */
    public static function listFor(User $actor): array
    {
        $editors = EditorWork::editors();
        $ids = array_column($editors, 'id');
        $schedules = self::schedules($ids);
        $from = now(config('app.timezone'))->subDays(30)->toDateString();
        $off = EditorDayOff::whereIn('user_id', $ids ?: [0])->where('date', '>=', $from)->orderBy('date')->get(['user_id', 'date', 'note'])->groupBy('user_id');
        $role = (string) $actor->role?->description;

        return array_map(fn ($e) => $e + [
            'schedule' => $schedules[$e['id']] ?? null, 'schedule_text' => EditorPaceRules::describe($schedules[$e['id']] ?? null),
            'days_off' => collect($off[$e['id']] ?? [])->map(fn ($d) => ['date' => substr((string) $d->date, 0, 10), 'note' => $d->note])->values()->all(),
            'can_edit' => EditorPaceRules::canEdit($role, (int) $actor->id, $e['leader'] ? EditorRules::LEADER : EditorRules::EDITOR, (int) $e['id']),
        ], $editors);
    }

    /** Pone (o cambia) el horario de un editor. */
    public static function setSchedule(User $actor, User $target, array $days, string $start, string $end): EditorSchedule
    {
        self::authorize($actor, $target);
        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);
        if ($problem = EditorPaceRules::invalid($days, $start, $end)) {
            throw ValidationException::withMessages(['schedule' => $problem]);
        }

        return EditorSchedule::updateOrCreate(['user_id' => $target->id], ['days' => $days, 'start_time' => $start, 'end_time' => $end, 'updated_by' => $actor->id]);
    }

    /** Marca (o quita) un día de permiso: ese día no cuenta para su meta. */
    public static function setDayOff(User $actor, User $target, string $date, bool $off, ?string $note): void
    {
        self::authorize($actor, $target);
        if ($off) {
            EditorDayOff::updateOrCreate(['user_id' => $target->id, 'date' => $date], ['note' => $note !== null ? (mb_substr(trim($note), 0, 160) ?: null) : null, 'created_by' => $actor->id]);
        } else {
            EditorDayOff::where('user_id', $target->id)->where('date', $date)->delete();
        }
    }

    private static function authorize(User $actor, User $target): void
    {
        $actor->loadMissing('role');
        $target->loadMissing('role');
        if ($target->deactivated_at !== null || !EditorPaceRules::canEdit((string) $actor->role?->description, (int) $actor->id, (string) $target->role?->description, (int) $target->id)) {
            abort(403, 'El horario del editor líder lo pone el dueño; el de cada editor de video, el líder.');
        }
    }
}
