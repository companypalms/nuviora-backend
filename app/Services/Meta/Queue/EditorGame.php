<?php

namespace App\Services\Meta\Queue;

use App\Models\CreativeBatch;
use App\Models\CreativeBatchDelivery;
use App\Services\Meta\Products\ProductModes;
use Illuminate\Support\Facades\DB;

/**
 * `.94` (dueño, entrevista del rol de editor: "todo debe ser muy intuitivo, fácil de utilizar y gamificado"): los números
 * de verdad del juego de los editores (guias/DISENO-UX-UI.md, regla 4: nada de puntos inventados).
 * - Cada editor: su racha a tiempo, cuántos le aprobaron a la primera este mes y cuántos de sus creativos ganaron.
 * - El líder, además, los del equipo: su racha al día (nada esperando revisión de más), el equipo a tiempo este mes y
 *   los ganadores del equipo.
 * Las fechas son de Venezuela (config app.timezone). Sin dinero.
 */
class EditorGame
{
    /** Hasta cuántos días atrás se mira una racha. */
    public const STREAK_DAYS = 90;

    /**
     * Días seguidos "bien", contando hacia atrás desde hoy. Cada día puede ser true (bien), false (corta la racha) o no
     * estar (un día sin nada que hacer no cuenta ni corta). Hoy: si todavía no está bien, no la corta (el día no terminó),
     * salvo que ya esté mal (false).
     *
     * @param array<string, bool|null> $days 'YYYY-MM-DD' => true | false | null (hoy, todavía en curso)
     */
    public static function streak(array $days, string $today): int
    {
        krsort($days);
        $n = 0;
        foreach ($days as $day => $ok) {
            if ($day > $today) {
                continue;
            }
            if ($ok === null) {
                continue; // hoy en curso: ni suma ni corta
            }
            if ($ok === false) {
                break;
            }
            $n++;
        }

        return $n;
    }

    /**
     * Racha a tiempo de un editor: cada día en que vencía algo suyo está bien si todo lo de ese día se entregó por primera
     * vez a tiempo. Lo que venció y no se entregó corta la racha; lo de hoy sin entregar todavía no.
     *
     * @param list<array{due: string, delivered: ?string}> $batches due = el plazo, delivered = la primera entrega (fecha)
     * @return array<string, bool|null>
     */
    public static function onTimeDays(array $batches, string $today): array
    {
        $days = [];
        foreach ($batches as $b) {
            $due = substr($b['due'], 0, 10);
            if ($due > $today) {
                continue;
            }
            $ok = $b['delivered'] !== null ? substr($b['delivered'], 0, 10) <= $due : ($due < $today ? false : null);
            // Un día con algo tarde queda mal; con algo de hoy todavía sin entregar, en curso (salvo que ya esté mal)
            $days[$due] = match (true) {
                ($days[$due] ?? true) === false || $ok === false => false,
                ($days[$due] ?? true) === null || $ok === null => null,
                default => true,
            };
        }

        return $days;
    }

    /**
     * Racha al día del líder: cada día en que hubo algo para revisar está bien si nada esperó más que los días de
     * correcciones (entregado el lunes con 1 día: el martes todavía está al día; el miércoles sin revisar, no).
     *
     * @param list<array{delivered: string, reviewed: ?string}> $deliveries fechas (las que no se revisaron: reviewed null)
     * @return array<string, bool>
     */
    public static function reviewDays(array $deliveries, string $today, int $correctionDays): array
    {
        $days = [];
        $from = date('Y-m-d', strtotime("{$today} -" . self::STREAK_DAYS . ' days'));
        foreach ($deliveries as $d) {
            $start = max(substr($d['delivered'], 0, 10), $from);
            $end = $d['reviewed'] !== null ? substr($d['reviewed'], 0, 10) : $today;
            $limit = date('Y-m-d', strtotime(substr($d['delivered'], 0, 10) . " +{$correctionDays} days"));
            for ($day = $start; $day <= $end && $day <= $today; $day = date('Y-m-d', strtotime("{$day} +1 day"))) {
                $days[$day] = ($days[$day] ?? true) && $day <= $limit;
            }
        }

        return $days;
    }

    /**
     * Lo del juego para la pantalla de quien mira (Mis encargos y, para el líder, Encargos del equipo).
     *
     * @param array{id: int, role: ?string} $who
     * @return array{me: array, team: ?array}
     */
    public static function for(array $who, string $today, int $correctionDays): array
    {
        $monthStart = substr($today, 0, 7) . '-01';
        $since = date('Y-m-d', strtotime("{$today} -" . self::STREAK_DAYS . ' days'));
        $leader = $who['role'] === EditorRules::LEADER;

        // .99: lo que está en espera (su producto está parado) no corta la racha
        $mine = ProductModes::withoutHeld(CreativeBatch::where('editor_id', $who['id'])->whereNotNull('due_date')->where('due_date', '>=', $since)
            ->whereNotIn('status', ['proposed', 'discarded']))->get(['id', 'due_date', 'delivered_at']);
        $me = [
            'streak' => self::streak(self::onTimeDays(self::dueRows($mine), $today), $today),
            'first' => self::firstTime([$who['id']], $monthStart),
            'winners' => self::winners([$who['id']], $monthStart),
            // Lo que ganó en la última semana, para celebrarlo en su pantalla
            'recent_wins' => self::recentWins($who['id'], date('Y-m-d', strtotime("{$today} -7 days"))),
        ];
        if (!$leader) {
            return ['me' => $me, 'team' => null];
        }

        // El líder revisa lo de los demás (lo suyo va directo a la banca)
        $deliveries = CreativeBatchDelivery::where('editor_id', '!=', $who['id'])->where(fn ($q) => $q->where('review', 'pending')
            ->orWhere('reviewed_at', '>=', $since))->get(['created_at', 'reviewed_at', 'review']);
        $teamMonth = CreativeBatch::whereNotNull('editor_id')->whereNotNull('due_date')->whereNotNull('delivered_at')
            ->where('delivered_at', '>=', $monthStart)->get(['due_date', 'delivered_at']);

        return ['me' => $me, 'team' => [
            'streak' => self::streak(self::reviewDays($deliveries->map(fn ($d) => [
                'delivered' => $d->created_at->setTimezone(config('app.timezone'))->toDateString(),
                'reviewed' => $d->review === 'pending' ? null : $d->reviewed_at?->setTimezone(config('app.timezone'))->toDateString(),
            ])->all(), $today, $correctionDays), $today),
            'on_time' => ['ok' => $teamMonth->filter(fn ($b) => EditorRules::onTime($b->delivered_at->setTimezone(config('app.timezone'))->toDateString(), $b->due_date->toDateString()))->count(),
                'total' => $teamMonth->count()],
            'winners' => self::winners(null, $monthStart),
        ]];
    }

    /** @return list<array{due: string, delivered: ?string}> */
    private static function dueRows($batches): array
    {
        return $batches->map(fn ($b) => ['due' => $b->due_date->toDateString(),
            'delivered' => $b->delivered_at?->setTimezone(config('app.timezone'))->toDateString()])->values()->all();
    }

    /**
     * A la primera este mes: de los encargos cuya primera entrega se revisó este mes, cuántos se aprobaron sin devolución
     * (lo que el líder se aprueba a sí mismo no cuenta).
     *
     * @param list<int> $editorIds
     * @return array{ok: int, total: int}
     */
    private static function firstTime(array $editorIds, string $monthStart): array
    {
        $rows = CreativeBatchDelivery::whereIn('editor_id', $editorIds)->where('version', 1)->whereIn('review', ['approved', 'returned'])
            ->where('reviewed_at', '>=', $monthStart)->whereColumn('reviewed_by', '!=', 'editor_id')->get(['review']);

        return ['ok' => $rows->where('review', 'approved')->count(), 'total' => $rows->count()];
    }

    /** Creativos (piezas) que ganaron este mes: de esos editores, o de todo el equipo (null). */
    private static function winners(?array $editorIds, string $monthStart): int
    {
        return DB::table('creative_batch_pieces as p')->join('creative_batches as b', 'b.id', '=', 'p.creative_batch_id')
            ->where('p.result', 'Ganó')->where('p.result_at', '>=', $monthStart)
            ->when($editorIds !== null, fn ($q) => $q->whereIn('b.editor_id', $editorIds), fn ($q) => $q->whereNotNull('b.editor_id'))
            ->count();
    }

    /** @return list<array{code: string, at: string}> */
    private static function recentWins(int $editorId, string $since): array
    {
        return DB::table('creative_batch_pieces as p')->join('creative_batches as b', 'b.id', '=', 'p.creative_batch_id')
            ->where('b.editor_id', $editorId)->where('p.result', 'Ganó')->where('p.result_at', '>=', $since)->whereNotNull('p.number')
            ->orderByDesc('p.result_at')->limit(5)->get(['p.number', 'p.result_at'])
            ->map(fn ($r) => ['code' => QueueRules::pieceCode((int) $r->number), 'at' => (string) $r->result_at])->all();
    }
}
