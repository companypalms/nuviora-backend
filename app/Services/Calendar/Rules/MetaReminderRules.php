<?php

namespace App\Services\Calendar\Rules;

use Carbon\CarbonImmutable;

/**
 * `.67` (dueño, 2026-11-01): "que en el calendario se vea todo lo que tengo que revisar en sus respectivos tiempos, por
 * ejemplo, revisar lo que tenga que ver con el flujo correcto de trabajo del editor de contenido". Los recordatorios de
 * Meta Ads y de los editores, en PHP puro (los datos los lee Readers\MetaReminderFacts):
 *
 * - todos los días (de lunes a sábado), en su propio bloque: los encargos por aprobar (con los testeos por decidir y los
 *   productos sin ficha), las entregas que solo revisa el dueño, que ningún encargo se quede quieto y los batches que
 *   esperan en la banca para lanzarse;
 * - cada semana: el trabajo de cada editor (los lunes, la semana pasada) y lo que aprendimos de los batches que
 *   terminaron su test (los sábados, los 7 días anteriores).
 * Se tildan solos cuando no queda nada. Lo de "ahora" solo sirve para hoy: otro día se muestra lo general.
 *
 * El camino de un encargo de video: aprobado → asignado (el líder) → en producción → entregado → revisado (el líder; las
 * entregas del líder las revisa el dueño, y todas si no hay otro líder). Las landings las revisa siempre el dueño.
 */
final class MetaReminderRules
{
    /** Cuánto puede quedarse quieto un encargo antes de avisar (en días). */
    public const UNASSIGNED_DAYS = 1;
    public const WAITING_DAYS = 1;
    public const RETURNED_DAYS = 2;

    /** Lo que se quedó quieto, en el orden en que se muestra. */
    public const STUCK = ['late' => 'Atrasado', 'unassigned' => 'Sin editor', 'waiting' => 'Esperando al líder', 'returned' => 'Devuelto'];

    /** Cómo se cuentan en la frase ("2 atrasados y 1 sin editor"). */
    private const STUCK_COUNT = ['late' => ['atrasado', 'atrasados'], 'unassigned' => ['sin editor', 'sin editor'],
        'waiting' => ['esperando al líder', 'esperando al líder'], 'returned' => ['devuelto sin volver a entregarse', 'devueltos sin volver a entregarse']];

    private const QUEUE_LINK = '/meta-ads/cola';
    private const LANDINGS_LINK = '/meta-ads/landings?tab=encargos';

    /**
     * Completa lo que dice un recordatorio de Meta Ads en una fecha ($out viene armado por ReminderRules::occurrence).
     * $facts['meta_work'] es lo de ahora (MetaReminderFacts::now); 'editor_weeks' y 'learnings', lo de cada semana.
     */
    public static function fill(string $key, array &$out, string $due, string $today, array $facts): void
    {
        $work = $due === $today ? ($facts['meta_work'] ?? null) : null;
        match ($key) {
            'meta_queue' => self::queue($out, $work),
            'meta_deliveries' => self::deliveries($out, $work),
            'meta_flow' => self::flow($out, $work),
            'meta_bank' => self::bank($out, $work, $today),
            'editors_week' => self::editorsWeek($out, $due, $today, $facts),
            'learnings_week' => self::learningsWeek($out, $due, $today, $facts),
        };
    }

    /**
     * Lo que espera la revisión del dueño: las entregas de video que no puede revisar ningún líder (las del líder, o todas
     * si no hay otro), las entregas de landings y las landings aprobadas para publicar.
     *
     * @param list<array> $open los encargos abiertos (MetaReminderFacts: code, product, landing, status, editor, pending_by, pending_at…)
     * @param array<int, string> $leaders los editores líderes activos (id => nombre)
     * @return list<array{code: string, product: ?string, landing: bool, kind: string, editor: ?string, leader: bool, at: ?string}>
     */
    public static function toReview(array $open, array $leaders): array
    {
        $out = [];
        foreach ($open as $o) {
            $byLeader = $o['pending_by'] !== null && isset($leaders[$o['pending_by']]);
            $owner = $o['landing'] || array_diff_key($leaders, [(int) $o['pending_by'] => true]) === [];
            if ($o['status'] === 'review' && $o['pending_by'] !== null && $owner) {
                $out[] = ['code' => $o['code'], 'product' => $o['product'], 'landing' => $o['landing'], 'kind' => 'review',
                    'editor' => $o['pending_editor'] ?? $o['editor'], 'leader' => $byLeader, 'at' => $o['pending_at']];
            } elseif ($o['landing'] && $o['status'] === 'ready') {
                $out[] = ['code' => $o['code'], 'product' => $o['product'], 'landing' => true, 'kind' => 'publish',
                    'editor' => $o['editor'], 'leader' => false, 'at' => $o['status_at']];
            }
        }
        usort($out, fn ($a, $b) => [$a['kind'] === 'publish', $a['at'] ?? ''] <=> [$b['kind'] === 'publish', $b['at'] ?? '']);

        return $out;
    }

    /**
     * Lo que se quedó quieto: atrasado (pasó su fecha y no se entregó), sin editor más de 1 día, una entrega esperando al
     * líder más de 1 día o un devuelto que no se volvió a entregar en 2 días. Cada encargo sale una sola vez, por lo más
     * grave. Las landings sin editor solo avisan si hay un editor de landings (si no, las hace el dueño).
     *
     * @param list<array> $open
     * @param array<int, string> $leaders
     * @param string $now "Y-m-d H:i:s"
     * @return list<array{code: string, product: ?string, landing: bool, kind: string, editor: ?string, since: string, leaders: list<string>}>
     */
    public static function stuck(array $open, array $leaders, bool $landingEditor, string $now): array
    {
        $today = substr($now, 0, 10);
        $before = fn (?string $at, int $days) => $at !== null && $at < CarbonImmutable::parse($now)->subDays($days)->format('Y-m-d H:i:s');
        $out = [];
        foreach ($open as $o) {
            $working = in_array($o['status'], ['approved', 'production'], true);
            $row = ['code' => $o['code'], 'product' => $o['product'], 'landing' => $o['landing'], 'editor' => $o['editor'], 'leaders' => []];
            if ($working && $o['due_date'] !== null && $o['due_date'] < $today) {
                $out[] = $row + ['kind' => 'late', 'since' => $o['due_date']];
            } elseif ($o['status'] === 'approved' && $o['editor_id'] === null && (!$o['landing'] || $landingEditor) && $before($o['approved_at'], self::UNASSIGNED_DAYS)) {
                $out[] = $row + ['kind' => 'unassigned', 'since' => substr($o['approved_at'], 0, 10)];
            } elseif ($o['status'] === 'production' && $o['last_review'] === 'returned' && $before($o['last_reviewed_at'], self::RETURNED_DAYS)) {
                $out[] = $row + ['kind' => 'returned', 'since' => substr($o['last_reviewed_at'], 0, 10)];
            } elseif (!$o['landing'] && $o['status'] === 'review' && $o['pending_by'] !== null && $before($o['pending_at'], self::WAITING_DAYS)) {
                $others = array_values(array_diff_key($leaders, [(int) $o['pending_by'] => true]));
                if ($others !== []) {
                    $out[] = ['editor' => $o['pending_editor'] ?? $o['editor'], 'leaders' => $others] + $row + ['kind' => 'waiting', 'since' => substr($o['pending_at'], 0, 10)];
                }
            }
        }
        $order = array_flip(array_keys(self::STUCK));
        usort($out, fn ($a, $b) => [$order[$a['kind']], $a['since'], $a['code']] <=> [$order[$b['kind']], $b['since'], $b['code']]);

        return $out;
    }

    /**
     * Cómo le fue a cada editor en una semana: entregados (la primera entrega) y a tiempo, revisados y devueltos, y el
     * resultado de lo que terminó su test.
     */
    public static function editorLine(array $r): array
    {
        $note = [$r['on_time'] . ' a tiempo'];
        if ($r['reviewed'] > 0) {
            $note[] = $r['returned'] > 0 ? self::count($r['returned'], 'devuelto', 'devueltos') . ' de ' . self::count($r['reviewed'], 'revisado', 'revisados') : 'nada devuelto';
        }
        $results = self::results($r);
        if ($results !== null) {
            $note[] = $results;
        }

        return ['label' => $r['name'] . ($r['landing'] ? ' (landings)' : ''), 'value' => self::count($r['delivered'], 'entregado', 'entregados'),
            'note' => implode(' · ', $note)];
    }

    /** "3 encargos", "1 encargo". */
    public static function count(int $n, string $one, string $many): string
    {
        return $n === 1 ? "1 {$one}" : "{$n} {$many}";
    }

    private static function queue(array &$out, ?array $work): void
    {
        $out['actions'] = [['label' => 'Ir a Cola y banca', 'link' => self::QUEUE_LINK]];
        if ($work === null) {
            $out['details'] = 'Después de las 7:00 la IA propone los encargos del día: apruébalos o descártalos para que los editores tengan trabajo.';

            return;
        }
        $videos = array_sum($work['proposed']['videos']);
        $landings = array_sum($work['proposed']['landings']);
        foreach ($work['proposed']['videos'] as $product => $n) {
            $out['lines'][] = ['label' => (string) $product, 'value' => self::count($n, 'encargo', 'encargos'), 'note' => 'de video, por aprobar'];
        }
        foreach ($work['proposed']['landings'] as $product => $n) {
            $out['lines'][] = ['label' => (string) $product, 'value' => self::count($n, 'encargo', 'encargos'), 'note' => 'de landing, por aprobar'];
        }
        foreach ($work['testing'] as $t) {
            $out['lines'][] = ['label' => $t['product'], 'value' => 'Decidir', 'note' => match (true) {
                $t['no_angles'] => 'está en testeo, pero su ficha no tiene ángulos: agrégalos para que se pidan los batches',
                $t['can_next'] => "la ronda {$t['round']} del testeo terminó sin ganador: prueba los siguientes ángulos o revisa la oferta o la landing",
                default => "la ronda {$t['round']} del testeo terminó sin ganador y no quedan ángulos: agrega ángulos a la ficha o revisa la oferta o la landing",
            }];
        }
        foreach ($work['no_profile'] as $name) {
            $out['lines'][] = ['label' => $name, 'value' => 'Sin ficha', 'note' => 'la IA no le propone encargos hasta que llenes su ficha'];
        }

        $parts = [];
        if ($videos + $landings > 0) {
            $parts[] = ($videos > 0 ? self::count($videos, 'encargo de video', 'encargos de video') . ($landings > 0 ? ' y ' . $landings . ' de landing' : '')
                : self::count($landings, 'encargo de landing', 'encargos de landing')) . ' por aprobar.';
        }
        if ($work['testing'] !== []) {
            $parts[] = self::count(count($work['testing']), 'testeo', 'testeos') . ' por decidir.';
        }
        if ($work['no_profile'] !== []) {
            $parts[] = self::count(count($work['no_profile']), 'producto activo sin ficha', 'productos activos sin ficha') . (count($work['no_profile']) === 1 ? ': no recibe encargos.' : ': no reciben encargos.');
        }
        $out['details'] = $parts ? implode(' ', $parts) : 'No hay encargos por aprobar ni testeos por decidir.';
        $out['auto_done'] = $parts === [];
        $out['auto_note'] = $parts === [] ? 'No hay nada por aprobar ni por decidir.' : null;
        if ($videos === 0 && $landings > 0) {
            $out['link'] = self::LANDINGS_LINK;
        }
        if ($landings > 0) {
            $out['actions'][] = ['label' => 'Ir a encargos de landing', 'link' => self::LANDINGS_LINK];
        }
    }

    private static function deliveries(array &$out, ?array $work): void
    {
        $out['actions'] = [['label' => 'Ir a Cola y banca', 'link' => self::QUEUE_LINK]];
        if ($work === null) {
            $out['details'] = 'Las entregas que revisas tú (las del editor líder, las de los editores si no hay otro líder y las de landings) y las landings aprobadas para publicar.';

            return;
        }
        $list = self::toReview($work['open'], $work['leaders']);
        $videos = count(array_filter($list, fn ($r) => !$r['landing']));
        $landings = count(array_filter($list, fn ($r) => $r['landing'] && $r['kind'] === 'review'));
        $publish = count($list) - $videos - $landings;
        foreach ($list as $r) {
            $when = $r['at'] ? ' el ' . self::when($r['at']) : '';
            $out['lines'][] = [
                'label' => $r['code'] . ($r['product'] ? ' · ' . $r['product'] : ''),
                'value' => $r['kind'] === 'publish' ? 'Para publicar' : 'Por revisar',
                'note' => $r['kind'] === 'publish'
                    ? 'aprobada' . $when . ': publícala en Shopify y toca "Publicada"'
                    : ($r['landing'] ? 'landing de ' : 'de ') . ($r['editor'] ?? 'un editor') . ($r['leader'] ? ' (el líder: no puede aprobarse solo)' : '') . ', entregó' . $when,
            ];
        }
        $parts = [];
        if ($videos + $landings > 0) {
            $parts[] = $videos > 0
                ? self::count($videos, 'entrega de video', 'entregas de video') . ($landings > 0 ? ' y ' . $landings . ' de landing' : '')
                : self::count($landings, 'entrega de landing', 'entregas de landing');
        }
        $text = $parts ? $parts[0] . ' por revisar' : '';
        if ($publish > 0) {
            $text .= ($text ? ', y ' : '') . self::count($publish, 'landing para publicar', 'landings para publicar');
        }
        $out['details'] = $text !== '' ? $text . '.' : 'No hay entregas esperando por ti.';
        $out['auto_done'] = $list === [];
        $out['auto_note'] = $list === [] ? 'No hay entregas esperando por ti.' : null;
        if ($videos === 0 && $list !== []) {
            $out['link'] = self::LANDINGS_LINK;
        }
        if ($landings + $publish > 0) {
            $out['actions'][] = ['label' => 'Ir a encargos de landing', 'link' => self::LANDINGS_LINK];
        }
    }

    private static function flow(array &$out, ?array $work): void
    {
        $out['actions'] = [['label' => 'Ir a Cola y banca', 'link' => self::QUEUE_LINK]];
        if ($work === null) {
            $out['details'] = 'Que ningún encargo se quede quieto: sin editor, atrasado, esperando al líder o devuelto sin volver a entregarse.';

            return;
        }
        $list = self::stuck($work['open'], $work['leaders'], $work['landing_editor'], $work['now']);
        foreach ($list as $r) {
            $who = $r['editor'] ?? null;
            $out['lines'][] = [
                'label' => $r['code'] . ($r['product'] ? ' · ' . $r['product'] : ''),
                'value' => self::STUCK[$r['kind']],
                'note' => match ($r['kind']) {
                    'late' => ($who ?? 'sin editor') . ': se tenía que entregar el ' . Schedule::dayName($r['since']),
                    'unassigned' => 'aprobado el ' . Schedule::dayName($r['since']) . ($r['landing'] ? ': asígnalo en "Quién lo hace"' : ': el líder todavía no se lo asigna a nadie'),
                    'waiting' => ($who ?? 'el editor') . ' entregó el ' . Schedule::dayName($r['since']) . '; lo revisa ' . self::join($r['leaders'], ' o '),
                    'returned' => 'se le devolvió a ' . ($who ?? 'el editor') . ' el ' . Schedule::dayName($r['since']) . ' y todavía no lo vuelve a entregar',
                },
            ];
        }
        if ($list === []) {
            $out['details'] = 'Todo va al día: nada atrasado, nada sin editor y nada esperando.';
            $out['auto_done'] = true;
            $out['auto_note'] = 'Todos los encargos van al día.';

            return;
        }
        $counts = array_count_values(array_column($list, 'kind'));
        $parts = [];
        foreach (self::STUCK_COUNT as $kind => [$one, $many]) {
            if (isset($counts[$kind])) {
                $parts[] = self::count($counts[$kind], $one, $many);
            }
        }
        $out['details'] = self::count(count($list), 'encargo para mirar', 'encargos para mirar') . ': ' . self::join($parts) . '.';
        if (array_filter($list, fn ($r) => $r['landing'])) {
            $out['actions'][] = ['label' => 'Ir a encargos de landing', 'link' => self::LANDINGS_LINK];
        }
    }

    private static function bank(array &$out, ?array $work, string $today): void
    {
        $out['actions'] = [['label' => 'Ir a Cola y banca', 'link' => self::QUEUE_LINK]];
        if ($work === null) {
            $out['details'] = 'Los batches aprobados que esperan en la banca: lánzalos cuando el análisis de campañas lo pida (primero los del testeo).';

            return;
        }
        $list = $work['bank'];
        usort($list, fn ($a, $b) => [$a['round'] === null, $a['since'] ?? '', $a['code']] <=> [$b['round'] === null, $b['since'] ?? '', $b['code']]);
        foreach ($list as $b) {
            $days = $b['since'] ? (int) CarbonImmutable::parse($b['since'])->diffInDays(CarbonImmutable::parse($today)) : 0;
            $out['lines'][] = [
                'label' => $b['code'] . ($b['product'] ? ' · ' . $b['product'] : ''),
                'value' => $b['round'] !== null ? "Testeo · ronda {$b['round']}" : 'En banca',
                'note' => $b['since'] ? 'desde el ' . Schedule::dayName($b['since']) . ($days > 0 ? ' (' . self::count($days, 'día', 'días') . ')' : ' (hoy)') : null,
            ];
        }
        if ($list === []) {
            $out['details'] = 'No hay batches esperando en la banca.';
            $out['auto_done'] = true;
            $out['auto_note'] = 'La banca está vacía.';

            return;
        }
        $testing = count(array_filter($list, fn ($b) => $b['round'] !== null));
        $oldest = min(array_map(fn ($b) => $b['since'] ?? $today, $list));
        $out['details'] = self::count(count($list), 'batch listo para lanzar', 'batches listos para lanzar') . ($testing > 0 ? " ({$testing} del testeo)" : '') . '.'
            . ($oldest < $today ? ' El que más espera, desde el ' . Schedule::dayName($oldest) . '.' : '')
            . ' El análisis de campañas te dice cuál lanzar en cada producto; si hoy no lanzas más, tíldalo.';
    }

    private static function editorsWeek(array &$out, string $due, string $today, array $facts): void
    {
        [$from, $to] = ReminderRules::lastWeek(CarbonImmutable::parse($due));
        $label = 'Del ' . Schedule::dayName($from->toDateString()) . ' al ' . Schedule::dayName($to->toDateString());
        $out['link'] = '/panel-dueno?desde=' . $from->toDateString() . '&hasta=' . $to->toDateString() . '&equipo=editors';
        $out['actions'] = [['label' => 'Abrir Equipo', 'link' => $out['link']]];
        $rows = $facts['editor_weeks'][$from->toDateString()] ?? null;
        if ($from->toDateString() > $today || $rows === null) {
            $out['details'] = $label . ': lo que entregue cada editor esa semana, si fue a tiempo, lo devuelto y cómo les fue en Meta.';

            return;
        }
        $out['lines'] = array_map([self::class, 'editorLine'], $rows);
        $open = $to->toDateString() >= $today ? ' La semana todavía no termina: es lo de hasta ahora.' : '';
        if ($rows === []) {
            $out['details'] = $label . ': ningún editor entregó ni terminó nada esa semana.' . $open;
            $out['auto_done'] = $open === '';
            $out['auto_note'] = $open === '' ? 'Ningún editor tuvo trabajo esa semana.' : null;

            return;
        }
        $sum = fn (string $k) => array_sum(array_column($rows, $k));
        $text = $label . ': ' . self::count($sum('delivered'), 'encargo entregado', 'encargos entregados') . ' (' . $sum('on_time') . ' a tiempo)';
        if ($sum('reviewed') > 0) {
            $text .= ', ' . self::count($sum('returned'), 'devuelto', 'devueltos') . ' de ' . self::count($sum('reviewed'), 'revisado', 'revisados');
        }
        $results = self::results(['won' => $sum('won'), 'lost' => $sum('lost'), 'tie' => $sum('tie')]);
        $out['details'] = $text . ($results ? '; de lo que terminó su test, ' . $results : '') . '.' . $open;
    }

    private static function learningsWeek(array &$out, string $due, string $today, array $facts): void
    {
        $d = CarbonImmutable::parse($due);
        $label = 'Del ' . Schedule::dayName($d->subDays(7)->toDateString()) . ' al ' . Schedule::dayName($d->subDay()->toDateString());
        $out['actions'] = [['label' => 'Abrir Lo que sabemos', 'link' => $out['link']]];
        $rows = $facts['learnings'][$due] ?? null;
        if ($due > $today || $rows === null) {
            $out['details'] = $label . ': los batches que terminen su test, qué ganó, qué perdió y lo que aprendimos de cada uno.';

            return;
        }
        if ($rows === []) {
            $out['details'] = $label . ': ningún batch terminó su test.';
            $out['auto_done'] = true;
            $out['auto_note'] = 'Ningún batch terminó su test esa semana.';

            return;
        }
        $out['lines'] = array_map(fn ($r) => [
            'label' => $r['code'] . ($r['product'] ? ' · ' . $r['product'] : ''),
            'value' => $r['result'],
            'note' => $r['learning'] ? mb_strimwidth($r['learning'], 0, 160, '…') : 'todavía sin aprendizaje: lo escribe la IA a la mañana siguiente',
        ], $rows);
        $counts = array_count_values(array_column($rows, 'result'));
        $results = self::results(['won' => $counts['Ganó'] ?? 0, 'lost' => $counts['Perdió'] ?? 0, 'tie' => $counts['Empate'] ?? 0]);
        $out['details'] = $label . ': ' . self::count(count($rows), 'batch terminó su test', 'batches terminaron su test') . ($results ? " ({$results})" : '')
            . '. Revisa lo que aprendimos de cada uno ("Corregir" si está mal): la IA lo usa en los encargos siguientes.';
    }

    /** "ganó 2, perdió 1 y empate 1" (null si no hay ninguno). */
    private static function results(array $r): ?string
    {
        $parts = [];
        foreach (['won' => 'ganó', 'lost' => 'perdió', 'tie' => 'empate'] as $k => $label) {
            if (($r[$k] ?? 0) > 0) {
                $parts[] = "{$label} {$r[$k]}";
            }
        }

        return $parts ? self::join($parts) : null;
    }

    /** "el lunes 26/10 a las 15:20". */
    private static function when(string $at): string
    {
        $d = CarbonImmutable::parse($at);

        return Schedule::dayName($d->toDateString()) . ' a las ' . $d->format('G:i');
    }

    private static function join(array $parts, string $and = ' y '): string
    {
        $last = array_pop($parts);

        return $parts ? implode(', ', $parts) . $and . $last : (string) $last;
    }
}
