<?php

namespace App\Services\Calendar\Rules;

use Carbon\CarbonImmutable;

/**
 * Los recordatorios que el calendario pone solo (Etapa 3, paso 2; entrevista del dueño del 2026-10-29). PHP puro: qué es
 * cada uno, cuándo toca, qué dice en cada fecha y cuándo se tilda solo, a partir de los datos que le pasan (los lee
 * Readers\ReminderFacts).
 *
 * - Todos los días (de lunes a sábado), la rutina: la tasa, la conciliación, lo que hay por aprobar, el inventario bajo y
 *   los números de los últimos 3 días. No se acumulan: la de hoy reemplaza a la de ayer.
 * - Los jueves, la semana pasada (ya llegaron los cierres de las agencias); el día 8, el mes pasado; el último día hábil
 *   del mes, las metas del mes siguiente. Estos sí quedan atrasados si no se hacen.
 * - Los pagos de la semana (paso 3): los lunes, a las vendedoras (lo que ganó cada una de lunes a domingo, como en su
 *   pantalla de Ganancias); los miércoles, a las agencias (sus cierres de la semana pasada). Los gastos fijos de Finanzas
 *   van aparte (ExpenseRules).
 * - Se tildan solos cuando el sistema ve que ya está hecho (la tasa guardada, nada por conciliar, nada por aprobar, nada en
 *   rojo, las metas puestas). Las revisiones de números las tilda el dueño.
 * - `.67`: Meta Ads y los editores van en su propio bloque (group "meta"): el análisis de campañas, los encargos por
 *   aprobar, las entregas que esperan al dueño, que los editores vayan al día y la banca; y cada semana, el trabajo de los
 *   editores (lunes) y lo que aprendimos (sábado). Lo que dicen lo arma MetaReminderRules.
 * - `.116`: el ángulo que propone la IA, solo cuando hay uno por decidir (hidden: los demás días no sale).
 */
final class ReminderRules
{
    /** El día de la semana de la revisión semanal se puede cambiar (de lunes a sábado). */
    public const DEFAULT_WEEKDAY = 4;

    /** Los que son "cada semana" y su día por defecto (el dueño los puede mover de lunes a sábado). */
    public const WEEKDAY_RULES = ['sellers_pay' => 1, 'agencies_pay' => 3, 'review_week' => self::DEFAULT_WEEKDAY, 'editors_week' => 1, 'learnings_week' => 6];

    public const RULES = [
        'sellers_pay' => [
            'type' => 'payment', 'group' => null, 'repeat' => 'weekly', 'accumulates' => true, 'auto' => false,
            'title' => 'Pagar a las vendedoras',
            'help' => 'Lo que ganó cada vendedora de lunes a domingo de la semana pasada: sus comisiones, sus upsells y, si es líder, su parte del grupo. Es lo mismo que ve cada una en Ganancias.',
            'link' => null,
        ],
        'agencies_pay' => [
            'type' => 'payment', 'group' => null, 'repeat' => 'weekly', 'accumulates' => true, 'auto' => true,
            'title' => 'Pagar a las agencias',
            'help' => 'El cierre de la semana pasada de cada agencia: lo que el sistema calculó y lo que cobra. Se paga desde Cierres de agencias; cuando todas quedan "Pagado", esto se tilda solo.',
            'link' => '/admin/cierres-agencias',
        ],
        'rate' => [
            'type' => 'task', 'group' => 'daily', 'repeat' => 'daily', 'accumulates' => false, 'auto' => true,
            'title' => 'Revisar la tasa del día',
            'help' => 'Los montos en bolívares salen de esta tasa. Si quedó vieja, se cobra de menos (o de más) en cada pedido.',
            'link' => '/admin/tasa-y-pagos',
        ],
        'reconciliation' => [
            'type' => 'task', 'group' => 'daily', 'repeat' => 'daily', 'accumulates' => false, 'auto' => true,
            'title' => 'Conciliación',
            'help' => 'Confirma con los extractos del banco que los pagos digitales (pago móvil, Zelle, Binance…) llegaron de verdad. Lo que no aparece en el extracto puede ser un comprobante falso.',
            'link' => '/conciliacion',
        ],
        'approvals' => [
            'type' => 'task', 'group' => 'daily', 'repeat' => 'daily', 'accumulates' => false, 'auto' => true,
            'title' => 'Comprobantes y vueltos por aprobar',
            'help' => 'Mientras un comprobante o un vuelto espera tu revisión, esa orden no avanza o la agencia no recibe su vuelto.',
            'link' => '/por-aprobar',
        ],
        'inventory' => [
            'type' => 'task', 'group' => 'daily', 'repeat' => 'daily', 'accumulates' => false, 'auto' => true,
            'title' => 'Revisar el inventario bajo',
            'help' => 'Los productos en rojo o en naranja se agotan pronto: si no se reabastecen a tiempo, se pierden ventas.',
            'link' => '/reabastecimiento',
        ],
        'meta_analysis' => [
            'type' => 'review', 'group' => 'meta', 'repeat' => 'daily', 'accumulates' => false, 'auto' => true,
            'title' => 'Revisar el análisis de campañas',
            'help' => 'Tu rutina de cada mañana (el análisis sale a las 12:30 a. m., hora de Venezuela): 1) lo urgente de tus campañas (qué subir, bajar o pausar en cada ciudad, con el monto); 2) qué hacer con cada creativo (ESCALAR, MANTENER, PARAR o ITERAR), que haces tú en el Administrador de anuncios; 3) lo que hay que iterar ya le llegó solo al editor líder, que lo reparte en "Encargos del equipo". Se tilda solo al abrirlo.',
            'link' => '/meta-ads/analisis',
        ],
        // .67 (dueño, 2026-11-01): "que en el calendario se vea todo lo que tengo que revisar en sus respectivos tiempos,
        // por ejemplo, revisar lo que tenga que ver con el flujo correcto de trabajo del editor de contenido"
        'meta_queue' => [
            'type' => 'task', 'group' => 'meta', 'repeat' => 'daily', 'accumulates' => false, 'auto' => true,
            'title' => 'Encargos por aprobar',
            'help' => 'Cada día, después de las 12:30 a. m., la IA propone los encargos del día: mientras no los apruebas, los editores no tienen trabajo nuevo. También avisa si un testeo terminó sin ganador y te toca decidir, o si a un producto activo le falta la ficha (sin ficha, la IA no le propone encargos). Se tilda solo cuando no queda nada.',
            'link' => '/meta-ads/cola',
        ],
        // .116 (dueño, 2026-11-09): "me gustaría que me lo propusiera a mí solamente (en mi calendario y en mis tareas por hacer en el día)"
        'meta_angles' => [
            'type' => 'task', 'group' => 'meta', 'repeat' => 'daily', 'accumulates' => false, 'auto' => true,
            'title' => 'Decidir el ángulo nuevo',
            'help' => 'A este producto ya no le quedan ángulos disponibles en su ficha y la IA te propone uno. Tú decides: ese, otro que recomiendes o uno de los que ya están. Mientras no decidas, no se piden batches nuevos de ese producto.',
            'link' => null,
        ],
        'meta_deliveries' => [
            'type' => 'review', 'group' => 'meta', 'repeat' => 'daily', 'accumulates' => false, 'auto' => true,
            'title' => 'Entregas que esperan por ti',
            'help' => 'Lo que solo tú puedes revisar: las entregas del editor líder (no puede aprobarse a sí mismo), las de los editores si no hay otro líder y las de landings; y las landings aprobadas que falta publicar. Mientras esperan, no llegan a la banca ni a Meta. Se tilda solo cuando no queda ninguna.',
            'link' => '/meta-ads/cola',
        ],
        'meta_flow' => [
            'type' => 'review', 'group' => 'meta', 'repeat' => 'daily', 'accumulates' => false, 'auto' => true,
            'title' => 'Que los editores vayan al día',
            'help' => 'El camino de cada encargo: aprobado → asignado → en producción → entregado → revisado. Avisa lo que se quedó quieto: un encargo atrasado (pasó su fecha y no se entregó), uno sin editor más de 1 día, una entrega esperando al líder más de 1 día o un devuelto que no se volvió a entregar en 2 días. Se tilda solo cuando todo va al día.',
            'link' => '/meta-ads/cola',
        ],
        'meta_bank' => [
            'type' => 'task', 'group' => 'meta', 'repeat' => 'daily', 'accumulates' => false, 'auto' => true,
            'title' => 'Batches listos para lanzar',
            'help' => 'Los batches aprobados que esperan en la banca para lanzarse en Meta (primero los del testeo). El análisis de campañas te dice cuál lanzar en cada producto; si hoy no lanzas más, tíldalo tú. Se tilda solo cuando la banca está vacía.',
            'link' => '/meta-ads/cola',
        ],
        // .48b-2: cada revisión abre su reporte del negocio y se tilda sola al abrirlo (decisión del dueño, 2026-10-31)
        'review_3days' => [
            'type' => 'review', 'group' => 'daily', 'repeat' => 'daily', 'accumulates' => false, 'auto' => true,
            'title' => 'Analizar los últimos 3 días',
            'help' => 'Los números de los 3 días anteriores (hoy no cuenta, todavía no termina): sirven para corregir a tiempo lo que va mal. Tu reporte diario sale a las 12:45 a. m. (hora de Venezuela) y se tilda solo al abrirlo.',
            'link' => null,
        ],
        'review_week' => [
            'type' => 'review', 'group' => null, 'repeat' => 'weekly', 'accumulates' => true, 'auto' => true,
            'title' => 'Revisar la semana pasada',
            'help' => 'El jueves ya llegaron los cierres de las agencias: se puede ver cuánto se ganó, cuánto se perdió y por producto, de lunes a domingo. Tu reporte semanal sale a las 12:45 a. m. (hora de Venezuela) y se tilda solo al abrirlo.',
            'link' => null,
        ],
        'editors_week' => [
            'type' => 'review', 'group' => null, 'repeat' => 'weekly', 'accumulates' => true, 'auto' => true,
            'title' => 'Revisar el trabajo de los editores',
            'help' => 'La semana pasada de cada editor, de lunes a domingo: cuántos encargos entregó y cuántos a tiempo, cuántos se le devolvieron y cómo les fue a los que terminaron su test. Es lo mismo de Panel del dueño › Equipo. Se tilda solo si nadie tuvo trabajo esa semana.',
            'link' => '/panel-dueno',
        ],
        'learnings_week' => [
            'type' => 'review', 'group' => null, 'repeat' => 'weekly', 'accumulates' => true, 'auto' => true,
            'title' => 'Lo que aprendimos esta semana',
            'help' => 'Los batches que terminaron su test en los 7 días anteriores: qué ganó, qué perdió y lo que aprendimos de cada uno. Revisa que el aprendizaje esté bien (si no, "Corregir"): la IA lo usa en los encargos siguientes. Se tilda solo si ninguno terminó.',
            'link' => '/meta-ads/lo-que-sabemos',
        ],
        'review_month' => [
            'type' => 'review', 'group' => null, 'repeat' => 'monthly', 'day' => 8, 'accumulates' => true, 'auto' => true,
            'title' => 'Revisar el mes pasado',
            'help' => 'Pasada la primera semana, casi todo lo del mes anterior ya se entregó o se canceló y llegaron los cierres: sus números ya son casi definitivos. Tu reporte mensual sale a las 12:45 a. m. (hora de Venezuela) y se tilda solo al abrirlo.',
            'link' => null,
        ],
        'goals_month' => [
            'type' => 'task', 'group' => null, 'repeat' => 'last_workday', 'accumulates' => true, 'auto' => true,
            'title' => 'Poner las metas del mes siguiente',
            'help' => 'Cada mes empieza sin metas. Si las dejas puestas antes, el panel te dice desde el primer día si vas bien.',
            'link' => '/panel-dueno?metas=1',
        ],
    ];

    /**
     * Los ajustes guardados, completos: cada recordatorio encendido o apagado, y el día de la revisión semanal.
     *
     * @return array<string, array{on: bool, weekday?: int}>
     */
    public static function normalizeSettings(mixed $saved): array
    {
        $saved = is_array($saved) ? $saved : [];
        $out = [];
        foreach (array_keys(self::RULES) as $key) {
            $row = is_array($saved[$key] ?? null) ? $saved[$key] : [];
            $out[$key] = ['on' => array_key_exists('on', $row) ? (bool) $row['on'] : true];
            if (isset(self::WEEKDAY_RULES[$key])) {
                $weekday = (int) ($row['weekday'] ?? self::WEEKDAY_RULES[$key]);
                $out[$key]['weekday'] = $weekday >= 1 && $weekday <= 6 ? $weekday : self::WEEKDAY_RULES[$key];
            }
        }

        return $out;
    }

    /** Qué está mal en los ajustes que llegaron (vacío: se pueden guardar). */
    public static function settingsProblems(array $input): array
    {
        $problems = [];
        foreach ($input as $key => $row) {
            if (!isset(self::RULES[$key])) {
                $problems["rules.{$key}"] = 'Ese recordatorio no existe.';
                continue;
            }
            if (isset($row['weekday']) && (!is_numeric($row['weekday']) || (int) $row['weekday'] < 1 || (int) $row['weekday'] > 6)) {
                $problems["rules.{$key}.weekday"] = 'Elige un día de lunes a sábado.';
            }
        }

        return $problems;
    }

    /** El patrón de fechas de un recordatorio, para Schedule. */
    public static function pattern(string $key, array $settings): array
    {
        $rule = self::RULES[$key];

        return [
            'repeat' => $rule['repeat'],
            'start' => '2020-01-01',
            'type' => $rule['type'],
            'weekday' => isset(self::WEEKDAY_RULES[$key]) ? ($settings[$key]['weekday'] ?? self::WEEKDAY_RULES[$key]) : null,
            'day' => $rule['day'] ?? null,
        ];
    }

    /**
     * Lo que dice un recordatorio en una fecha. $facts son los datos del sistema (ReminderFacts); los de "ahora" (la
     * conciliación, lo que hay por aprobar, el inventario) solo sirven para hoy: otro día se muestra lo general.
     *
     * Los pagos de la semana leen $facts['sellers'] y $facts['closures'] por el lunes de la semana que pagan.
     *
     * @return array{title: string, details: string, link: ?string, actions: list<array{label: string, link?: string, mark?: string, angle?: int}>,
     *               lines: list<array{label: string, value: string, note: ?string}>, auto_done: bool, auto_note: ?string, hidden?: bool}
     */
    public static function occurrence(string $key, string $due, string $today, array $facts): array
    {
        $rule = self::RULES[$key];
        $isToday = $due === $today;
        $out = ['title' => $rule['title'], 'details' => '', 'link' => $rule['link'], 'actions' => [], 'lines' => [], 'auto_done' => false, 'auto_note' => null];
        $d = CarbonImmutable::parse($due);

        switch ($key) {
            case 'sellers_pay':
                [$from, $to] = self::lastWeek($d);
                $rows = $facts['sellers'][$from->toDateString()] ?? [];
                $out['details'] = self::weekLabel($from, $to) . ': ' . match (true) {
                    $from->toDateString() > $today => 'lo que gane cada vendedora esa semana (todavía no empieza).',
                    $to->toDateString() >= $today => self::sellersSummary($rows) . ' La semana todavía no termina: es lo de hasta ahora.',
                    default => self::sellersSummary($rows),
                };
                $out['lines'] = array_map(fn (array $r) => [
                    'label' => $r['name'],
                    'value' => self::money($r['total']),
                    'note' => self::sellerParts($r),
                ], $rows);
                break;

            case 'agencies_pay':
                [$from, $to] = self::lastWeek($d);
                $rows = $facts['closures'][$from->toDateString()] ?? [];
                $paid = count(array_filter($rows, fn ($r) => $r['paid']));
                $out['details'] = self::weekLabel($from, $to) . ': '
                    . ($rows === [] && $due > $today ? 'los cierres de esa semana todavía no llegan.' : self::closuresSummary($rows));
                $out['lines'] = array_map(fn (array $r) => [
                    'label' => $r['agency'],
                    'value' => $r['amount'] !== null ? self::money($r['amount']) : '—',
                    'note' => $r['status_label'],
                ], $rows);
                // Un miércoles que todavía no llega no se da por hecho aunque no haya cierres abiertos
                if ($due <= $today && ($rows === [] || $paid === count($rows))) {
                    $out['auto_done'] = true;
                    $out['auto_note'] = $rows === [] ? 'No hubo cierres de agencias esa semana.' : 'Todas las agencias de esa semana están pagadas.';
                }
                $out['actions'] = [['label' => 'Ir a cierres', 'link' => $rule['link']]];
                break;

            case 'rate':
                $saved = $facts['rate_days'][$due] ?? null;
                $out['auto_done'] = $saved !== null;
                $out['auto_note'] = $saved !== null ? "Se guardó una tasa ese día, a las {$saved}." : null;
                $out['details'] = $isToday && !empty($facts['rates'])
                    ? self::ratesText($facts['rates'])
                    : 'Confirma que la tasa BCV y la de Binance estén al día; si cambiaron, actualízalas.';
                $out['actions'] = [['label' => 'Están bien', 'mark' => 'done'], ['label' => 'Cambiar', 'link' => $rule['link']]];
                break;

            case 'reconciliation':
                if ($isToday && isset($facts['reconciliation'])) {
                    $pending = $facts['reconciliation'];
                    $out['auto_done'] = $pending === [];
                    $out['auto_note'] = $pending === [] ? 'No queda ningún día por conciliar.' : null;
                    $out['details'] = $pending === [] ? 'No queda ningún día por conciliar.' : self::reconciliationText($pending);
                } else {
                    $out['details'] = 'Sube los extractos del banco del día anterior y revisa los pagos que no aparecen.';
                }
                $out['actions'] = [['label' => 'Ir a conciliación', 'link' => $rule['link']]];
                break;

            case 'approvals':
                if ($isToday && isset($facts['approvals'])) {
                    $text = self::approvalsText($facts['approvals']);
                    $out['auto_done'] = $text === null;
                    $out['auto_note'] = $text === null ? 'No hay nada por aprobar.' : null;
                    $out['details'] = $text ?? 'No hay nada por aprobar.';
                } else {
                    $out['details'] = 'Revisa los comprobantes, los vueltos por validar y los vueltos por pagar.';
                }
                $out['actions'] = [['label' => 'Ir a Por aprobar', 'link' => $rule['link']]];
                break;

            case 'inventory':
                if ($isToday && isset($facts['inventory'])) {
                    $text = self::inventoryText($facts['inventory']);
                    $out['auto_done'] = $text === null;
                    $out['auto_note'] = $text === null ? 'Ningún producto en rojo ni en naranja.' : null;
                    $out['details'] = $text ?? 'Ningún producto en rojo ni en naranja.';
                } else {
                    $out['details'] = 'Mira los productos en rojo o en naranja y si hay que reabastecer.';
                }
                $out['actions'] = [['label' => 'Ir a reabastecimiento', 'link' => $rule['link']]];
                break;

            case 'meta_analysis':
                $seen = $facts['meta_analysis_days'][$due] ?? null;
                $out['auto_done'] = $seen !== null;
                $out['auto_note'] = $seen !== null ? "Lo abriste a las {$seen}." : null;
                // .71: la rutina de la mañana, con lo de hoy
                $out['details'] = $isToday && isset($facts['meta_routine']) ? self::routineText($facts['meta_routine'])
                    : 'Lo urgente de tus campañas, qué hacer con cada creativo (ESCALAR, MANTENER, PARAR o ITERAR) y lo que le llegó solo al editor líder.';
                $out['actions'] = [['label' => 'Abrir el análisis', 'link' => $rule['link']]];
                break;

            case 'meta_queue':
            case 'meta_angles':
            case 'meta_deliveries':
            case 'meta_flow':
            case 'meta_bank':
            case 'editors_week':
            case 'learnings_week':
                MetaReminderRules::fill($key, $out, $due, $today, $facts);
                break;

            case 'review_3days':
                [$from, $to] = [$d->subDays(3), $d->subDay()];
                $out['details'] = 'Del ' . Schedule::dayName($from->toDateString()) . ' al ' . Schedule::dayName($to->toDateString()) . '.';
                self::reportReview($out, 'daily', $from, $to, $facts);
                break;

            case 'review_week':
                [$from, $to] = self::lastWeek($d);
                $out['details'] = 'Del ' . Schedule::dayName($from->toDateString()) . ' al ' . Schedule::dayName($to->toDateString())
                    . ': cuánto se ganó, cuánto se perdió y por producto.';
                self::reportReview($out, 'weekly', $from, $to, $facts);
                break;

            case 'review_month':
                $from = $d->startOfMonth()->subMonthNoOverflow();
                $to = $from->endOfMonth()->startOfDay();
                $out['title'] = 'Revisar el mes pasado (' . self::monthName($from) . ')';
                $out['details'] = 'Del ' . $from->format('j/n') . ' al ' . $to->format('j/n') . ': el mes completo.';
                self::reportReview($out, 'monthly', $from, $to, $facts);
                break;

            case 'goals_month':
                $next = $d->startOfMonth()->addMonthNoOverflow();
                $done = in_array($next->format('Y-m'), $facts['goal_months'] ?? [], true);
                $out['title'] = 'Poner las metas de ' . self::monthName($next);
                $out['details'] = 'Ventas, ganancia y efectividad de ' . self::monthName($next) . '. Tienes los números de este mes de referencia.';
                $out['auto_done'] = $done;
                $out['auto_note'] = $done ? 'Ya pusiste las metas de ' . self::monthName($next) . '.' : null;
                $out['actions'] = [['label' => 'Poner metas', 'link' => $rule['link']]];
                break;
        }

        return $out;
    }

    /**
     * `.71`: la rutina de hoy en una frase: "Lo urgente: 3 cosas. Creativos: 2 PARAR · 1 ESCALAR · 2 ITERAR. Al editor
     * líder le llegaron 2 encargos (1 espera cupo para mañana)."
     *
     * @param array{ready: bool, urgent: int, calls: array<string, int>, sent: int, waiting: int} $r
     */
    public static function routineText(array $r): string
    {
        if (!$r['ready']) {
            return 'El análisis de hoy sale a las 12:30 a. m. (hora de Venezuela): lo urgente, qué hacer con cada creativo y lo que le llega solo al editor líder.';
        }
        $parts = [$r['urgent'] > 0 ? 'Lo urgente: ' . ($r['urgent'] === 1 ? '1 cosa.' : "{$r['urgent']} cosas.") : 'Nada urgente en tus campañas.'];
        if ($r['calls']) {
            $parts[] = 'Creativos: ' . implode(' · ', array_map(fn ($label, $n) => "{$n} {$label}", array_keys($r['calls']), $r['calls'])) . '.';
        }
        if ($r['sent'] + $r['waiting'] > 0) {
            $parts[] = 'Al editor líder le ' . ($r['sent'] === 1 ? 'llegó 1 encargo' : "llegaron {$r['sent']} encargos")
                . ($r['waiting'] > 0 ? ' (' . $r['waiting'] . ' ' . ($r['waiting'] === 1 ? 'espera' : 'esperan') . ' cupo para mañana)' : '') . '.';
        }

        return implode(' ', $parts);
    }

    /** "BCV: $ 36,50 y € 39,80 · Binance: $ 40,10. Guardadas el 29/10 a las 9:12." */
    public static function ratesText(array $rates): string
    {
        $n = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', '.');
        $text = "BCV: \$ {$n($rates['bcv_usd'] ?? null)} y € {$n($rates['bcv_eur'] ?? null)} · Binance: \$ {$n($rates['binance_usd'] ?? null)}.";
        if (!empty($rates['updated_at'])) {
            $at = CarbonImmutable::parse($rates['updated_at']);
            $text .= ' Guardadas el ' . $at->format('j/n') . ' a las ' . $at->format('G:i') . '.';
        }

        return $text;
    }

    /**
     * "Faltan 2 días: lun 26/10 (falta 1 extracto), mar 27/10 (3 pagos por revisar)."
     *
     * @param list<array{date: string, missing_statements: int, incidents: int}> $days
     */
    public static function reconciliationText(array $days): string
    {
        $parts = array_map(function (array $day) {
            $why = [];
            if ($day['missing_statements'] > 0) {
                $why[] = $day['missing_statements'] === 1 ? 'falta 1 extracto' : "faltan {$day['missing_statements']} extractos";
            }
            if ($day['incidents'] > 0) {
                $why[] = $day['incidents'] === 1 ? '1 pago por revisar' : "{$day['incidents']} pagos por revisar";
            }

            return Schedule::dayName($day['date']) . ($why ? ' (' . implode(', ', $why) . ')' : ' (por terminar)');
        }, array_slice($days, 0, 5));
        $more = count($days) > 5 ? ' y ' . (count($days) - 5) . ' más' : '';

        return (count($days) === 1 ? 'Falta 1 día: ' : 'Faltan ' . count($days) . ' días: ') . implode(', ', $parts) . $more . '.';
    }

    /** "1 comprobante y 2 vueltos por pagar." (null: nada por aprobar). */
    public static function approvalsText(array $counts): ?string
    {
        $parts = [];
        if (($counts['receipts'] ?? 0) > 0) {
            $parts[] = $counts['receipts'] === 1 ? '1 comprobante por revisar' : "{$counts['receipts']} comprobantes por revisar";
        }
        if (($counts['to_validate'] ?? 0) > 0) {
            $parts[] = $counts['to_validate'] === 1 ? '1 vuelto por validar' : "{$counts['to_validate']} vueltos por validar";
        }
        if (($counts['to_pay'] ?? 0) > 0) {
            $parts[] = $counts['to_pay'] === 1 ? '1 vuelto por pagar' : "{$counts['to_pay']} vueltos por pagar";
        }

        return $parts ? self::joinList($parts) . '.' : null;
    }

    /** "2 productos en rojo y 1 en naranja." (null: ninguno). */
    public static function inventoryText(array $counts): ?string
    {
        $red = (int) ($counts['red'] ?? 0);
        $orange = (int) ($counts['orange'] ?? 0);
        if ($red === 0 && $orange === 0) {
            return null;
        }
        $parts = [];
        if ($red > 0) {
            $parts[] = ($red === 1 ? '1 producto' : "{$red} productos") . ' en rojo';
        }
        if ($orange > 0) {
            $parts[] = ($red > 0 ? $orange : ($orange === 1 ? '1 producto' : "{$orange} productos")) . ' en naranja';
        }

        return implode(' y ', $parts) . '.';
    }

    /** La semana de lunes a domingo anterior a ese día (la que se paga o se revisa). */
    public static function lastWeek(CarbonImmutable $day): array
    {
        $from = $day->startOfDay()->subDays($day->dayOfWeekIso - 1)->subWeek();

        return [$from, $from->addDays(6)];
    }

    /** "8 personas, total $282,50." */
    public static function sellersSummary(array $rows): string
    {
        if ($rows === []) {
            return 'nadie ganó comisiones esa semana.';
        }
        $total = array_sum(array_column($rows, 'total'));

        return (count($rows) === 1 ? '1 persona' : count($rows) . ' personas') . ', total ' . self::money($total) . '.';
    }

    /** "comisiones $15,00 · upsells $2,00 · líder $3,00" (sin las partes en cero). */
    public static function sellerParts(array $row): ?string
    {
        $parts = [];
        foreach (['orders' => 'comisiones', 'upsells' => 'upsells', 'leadership' => 'líder'] as $key => $label) {
            if (($row[$key] ?? 0) > 0.004) {
                $parts[] = $label . ' ' . self::money($row[$key]);
            }
        }

        return $parts ? implode(' · ', $parts) : null;
    }

    /** "3 agencias: 1 pagada y 2 por pagar ($240,00)." */
    public static function closuresSummary(array $rows): string
    {
        if ($rows === []) {
            return 'no hubo cierres de agencias.';
        }
        $unpaid = array_filter($rows, fn ($r) => !$r['paid']);
        $paid = count($rows) - count($unpaid);
        $owed = array_sum(array_map(fn ($r) => (float) ($r['amount'] ?? 0), $unpaid));
        $text = (count($rows) === 1 ? '1 agencia' : count($rows) . ' agencias') . ': ';
        if ($unpaid === []) {
            return $text . (count($rows) === 1 ? 'pagada.' : 'todas pagadas.');
        }
        $parts = [];
        if ($paid > 0) {
            $parts[] = $paid === 1 ? '1 pagada' : "{$paid} pagadas";
        }
        $parts[] = (count($unpaid) === 1 ? '1 por pagar' : count($unpaid) . ' por pagar') . ($owed > 0 ? ' (' . self::money($owed) . ')' : '');

        return $text . implode(' y ', $parts) . '.';
    }

    /** "$1.234,56" (como el resto del panel). */
    public static function money(float $value): string
    {
        return ($value < 0 ? '−$' : '$') . number_format(abs($value), 2, ',', '.');
    }

    public static function monthName(CarbonImmutable $d): string
    {
        return ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'][$d->month - 1];
    }

    /** "Del lunes 19/10 al domingo 25/10". */
    private static function weekLabel(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return 'Del ' . Schedule::dayName($from->toDateString()) . ' al ' . Schedule::dayName($to->toDateString());
    }

    /**
     * .48b-2: una revisión abre su reporte del negocio de esas fechas (y el Panel, de segundo) y se tilda sola cuando el
     * dueño abrió ese reporte (report_views: "tipo|desde|hasta" => la hora).
     */
    private static function reportReview(array &$out, string $type, CarbonImmutable $from, CarbonImmutable $to, array $facts): void
    {
        $seen = $facts['report_views']["{$type}|{$from->toDateString()}|{$to->toDateString()}"] ?? null;
        $out['auto_done'] = $seen !== null;
        $out['auto_note'] = $seen !== null ? "Abriste el reporte a las {$seen}." : null;
        $out['link'] = '/reportes-negocio?tipo=' . $type . '&desde=' . $from->toDateString() . '&hasta=' . $to->toDateString();
        $out['actions'] = [['label' => 'Abrir el reporte', 'link' => $out['link']], ['label' => 'Abrir panel', 'link' => self::panelLink($from, $to)]];
    }

    /** El Panel del dueño con esas fechas ya puestas. */
    private static function panelLink(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return '/panel-dueno?desde=' . $from->toDateString() . '&hasta=' . $to->toDateString();
    }

    private static function joinList(array $parts): string
    {
        $last = array_pop($parts);

        return $parts ? implode(', ', $parts) . ' y ' . $last : $last;
    }
}
