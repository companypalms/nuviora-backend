<?php

namespace App\Services\Meta\Queue;

use App\Services\Meta\Analysis\CreativeCalls;
use App\Services\Meta\Analysis\UrgentList;

/**
 * `.88` (dueño, 2026-11-03): lo que necesita cada producto para mantenerse y seguir escalando, y para cuándo ("si
 * necesitamos un batch para hoy, que diga lo necesitamos para hoy… que sea muy específico el sistema con qué necesitamos
 * para mantener cada producto y escalarlo y en cuánto tiempo"). La finalidad de cada campaña es seguir escalando: en C o
 * debajo se sube de a poco; si sube de C, aunque no llegue al BE, hace falta un batch nuevo para volver debajo de C. PHP
 * puro. Lo que decidió en la entrevista:
 * - para HOY, 1 batch nuevo (sirve para todas sus ciudades) si alguna ciudad está en el BE o encima, o entre C y el BE sin
 *   nada en su banca, o le quedan menos de 2 batches vivos sin nada en su banca (con los 3 días cerrados de sus reglas);
 * - la reserva: 1 batch listo por producto ("banca por producto: desde"), para dentro de 2 días;
 * - lo que ya viene en camino cuenta: no se pide otro, se adelanta su fecha.
 * El líder lee el porqué en palabras, sin montos (el editor no ve precios ni plata).
 *
 * `.100` (dueño, 2026-11-04: "nosotros lanzamos un batch a la vez… lanzamos un batch, esperamos resultados, vemos si
 * podemos escalar con ese batch o hace falta meter otro batch"; eligió "solo 1"): un producto que ya vende tiene **un solo
 * batch pedido a la vez** (haciéndose o en la banca). Cuando ese se lanza, se pide el siguiente. Para hoy solo si una
 * ciudad lo necesita y no tiene nada que lanzar; si tiene algo (de la banca o de otra ciudad), se lanza eso y lo que se
 * pide es el siguiente, con la fecha de la reserva. Los 2 batches vivos por ciudad siguen (lo eligió el dueño).
 */
final class DemandRules
{
    /** En el BE o encima. */
    private const AT_BE = ['intervene', 'decrease'];

    /** Un encargo que ya está en manos del equipo o listo en la banca. */
    public const OPEN = ['approved', 'production', 'review', 'bank'];

    /** El porqué de la reserva, para el líder. */
    public const RESERVE = 'Reserva: un batch listo para la primera ciudad que suba de su costo objetivo, y así seguir escalando sin parar.';

    /**
     * Lo de hoy de un producto: si hace falta un batch nuevo, en qué ciudades y por qué, y los batches de la banca que sus
     * misiones lanzan hoy (esos ya no quedan de reserva).
     *
     * @param array $d un producto de MetaDecisions::compute (campaigns con action y numbers)
     * @return array{today: int, cities: array<string, string>, consumed: list<string>, why: ?string}
     */
    public static function today(array $d): array
    {
        $mode = $d['product']['mode'] ?? 'normal';
        $cities = [];
        $consumed = [];
        foreach ($d['campaigns'] ?? [] as $c) {
            $a = $c['action'] ?? null;
            if (!$a || !($c['active'] ?? false)) {
                continue;
            }
            $key = $a['key'] === 'none' && ($a['label'] ?? null) === 'No se vende aquí' ? 'not_sold' : $a['key'];
            $n = $c['numbers'] ?? null;
            $offers = $n['offers'] ?? [];
            // .123: entre C y BE, mientras se espera a ver si el último cambio la devolvió a C, no hace falta un batch hoy
            $holds = !empty($a['ladder']['hold']);
            $need = UrgentList::need($key, $n, $mode, $holds);
            foreach (array_slice($offers, 0, $need) as $o) {
                if (($o['how'] ?? 'bank') === 'bank') {
                    $consumed[] = (string) $o['code'];
                }
            }
            $live = $n['live'] ?? null;
            // .100: con algo que lanzar (de la banca o de otra ciudad), hoy se lanza eso: no hace falta un batch nuevo para hoy
            $why = match (true) {
                $mode !== 'normal' || $offers !== [] => null,
                in_array($key, self::AT_BE, true) || $key === 'hold_prune' && !$holds || $need > 0 && $live !== null && $live < UrgentList::MIN_LIVE
                    => self::reason($holds ? 'hold' : $key, $live),
                default => null,
            };
            if ($why !== null) {
                $cities[(string) ($c['city'] ?? 'Una ciudad')] = $why;
            }
        }

        return ['today' => $cities === [] ? 0 : 1, 'cities' => $cities, 'consumed' => array_values(array_unique($consumed)),
            'why' => $cities === [] ? null : self::why($cities)];
    }

    /** Por qué una ciudad necesita un batch, en palabras y sin montos (`.89`: también para lo que llega a la banca). */
    public static function reason(string $key, ?int $live): ?string
    {
        return match (true) {
            in_array($key, self::AT_BE, true) => 'está en su límite o encima',
            $key === 'hold_prune' => 'subió de su costo objetivo',
            $live !== null && $live < UrgentList::MIN_LIVE => $live === 0 ? 'no le queda ningún batch vivo' : 'le queda 1 solo batch vivo',
            default => null,
        };
    }

    /** "Carabobo y Caracas subieron de su costo objetivo; Aragua está en su límite o encima: hace falta un batch nuevo hoy…" */
    public static function why(array $cities): string
    {
        $by = [];
        foreach ($cities as $city => $why) {
            $by[$why][] = $city;
        }
        $parts = [];
        foreach ($by as $why => $list) {
            $plural = count($list) > 1;
            // "a Lara le queda 1 solo batch vivo"
            $parts[] = (str_contains($why, 'le queda') ? 'a ' : '') . CreativeCalls::join($list) . ' '
                . ($plural ? strtr($why, ['está' => 'están', 'subió' => 'subieron', 'le queda' => 'les queda']) : $why);
        }

        return implode('; ', $parts) . ': hace falta un batch nuevo hoy para volver a bajar el costo por compra y seguir escalando.';
    }

    /**
     * `.100`: el único batch que hace falta y quién lo cubre. Su fecha: hoy si una ciudad lo necesita hoy; si no, la de la
     * reserva (si la reserva está en 0, nada). Lo cubre lo listo en la banca (menos lo que se lanza hoy), o lo que viene en
     * camino (si ahora hace falta antes, se adelanta su fecha); si no hay nada, un encargo nuevo. Lo que el dueño descartó
     * hoy cuenta como cubierto (no se vuelve a pedir el mismo día).
     *
     * @param list<array{id: int, status: string, due_date: ?string, code: ?string}> $open
     * @param list<string> $consumed los B#### de la banca que las misiones lanzan hoy
     * @return array{advance: array<int, string>, covered: array<int, string>, new: list<string>} fechas en Y-m-d
     */
    public static function slots(int $today, int $reserve, array $open, array $consumed, string $date, int $reserveDays): array
    {
        $out = ['advance' => [], 'covered' => [], 'new' => []];
        $want = match (true) {
            $today > 0 => $date,
            $reserve > 0 => date('Y-m-d', strtotime("{$date} +{$reserveDays} days")),
            default => null,
        };
        if ($want === null) {
            return $out;
        }
        $ready = array_values(array_filter($open, fn ($b) => $b['status'] === 'bank' && !in_array((string) $b['code'], $consumed, true)));
        $coming = array_values(array_filter($open, fn ($b) => in_array($b['status'], ['approved', 'production', 'review', 'discarded'], true)));
        usort($coming, fn ($a, $b) => [$a['due_date'] ?? '9', $a['id']] <=> [$b['due_date'] ?? '9', $b['id']]);
        if ($b = $ready[0] ?? null) {
            $out['covered'][$b['id']] = $want;
        } elseif ($b = $coming[0] ?? null) {
            $out['covered'][$b['id']] = $want;
            if ($b['status'] !== 'discarded' && ($b['due_date'] === null || $b['due_date'] > $want)) {
                $out['advance'][$b['id']] = $want;
            }
        } else {
            $out['new'][] = $want;
        }

        return $out;
    }
}
