<?php

namespace App\Services\Meta\Landings;

use App\Services\Meta\Queue\QueueRules;

/**
 * Los encargos de landing (Etapa 5, paso 6b), en PHP puro. El camino:
 * se crea (o lo proponen las reglas, en el paso 6c) → el dueño lo aprueba (L####) → el editor "Empezar" → "Entregar" el
 * enlace de vista previa de Shopify y qué cambió (V01, En revisión) → el dueño lo aprueba ("Para publicar") o lo devuelve
 * con un comentario → el editor la publica en Shopify y toca "Publicada" → test antes/después con los mismos anuncios →
 * "Testeado" solo, con el veredicto (LandingTestRules).
 *
 * Decisiones del dueño (2026-10-30):
 * - por ahora las landings las hace él: el Admin puede todo (también empezar, entregar, aprobar lo suyo y publicar);
 * - entrega la vista previa de Shopify; cuando el dueño aprueba, el editor la publica y toca "Publicada";
 * - nunca a la vez que un cambio de creativo: el test se mide solo con los mismos anuncios, y una landing con un test en
 *   curso no recibe otro encargo hasta que termine.
 */
final class LandingTaskRules
{
    public const ROLE = 'Editor de landings';
    public const ADMIN = 'Admin';

    public const STATUSES = [
        'proposed' => 'Propuesto', 'approved' => 'Aprobado', 'production' => 'En producción', 'review' => 'En revisión',
        'ready' => 'Para publicar', 'testing' => 'En test', 'tested' => 'Testeado', 'discarded' => 'Descartado',
    ];

    public const KINDS = ['speed' => 'Velocidad', 'angle' => 'Hablarle a un ángulo', 'new' => 'Landing nueva', 'offer' => 'Oferta o contenido'];

    /** Lo que se puede hacer: aprobar, asignar, empezar, entregar, revisar, publicar y descartar. */
    public const ACTIONS = ['approve', 'assign', 'start', 'deliver', 'review', 'publish', 'discard'];

    /** Los que siguen abiertos (no se crea otro encargo para la misma landing mientras tanto). */
    public const OPEN = ['proposed', 'approved', 'production', 'review', 'ready', 'testing'];

    private const WORKING = ['approved', 'production'];

    public static function code(int $number): string
    {
        return 'L' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    /** El siguiente número libre: después del más alto, y nunca menos de 100 (como los batches). */
    public static function next(iterable $used): int
    {
        return QueueRules::next($used);
    }

    /**
     * ¿Puede ver este encargo? El dueño, todos; el editor de landings, los suyos ya aprobados.
     *
     * @param array{id: int, role: ?string} $who
     * @param array{status: string, editor_id: ?int} $task
     */
    public static function canSee(array $who, array $task): bool
    {
        if ($who['role'] === self::ADMIN) {
            return true;
        }

        return $who['role'] === self::ROLE && $task['status'] !== 'proposed' && $task['editor_id'] !== null && (int) $task['editor_id'] === (int) $who['id'];
    }

    /**
     * @param array{id: int, role: ?string} $who
     * @param array{status: string, editor_id: ?int, pending?: bool} $task pending = hay una entrega esperando revisión
     */
    public static function can(string $action, array $who, array $task): bool
    {
        $admin = $who['role'] === self::ADMIN;
        // El dueño hace también lo del editor (por ahora las landings las hace él)
        $doer = $admin || $who['role'] === self::ROLE && $task['editor_id'] !== null && (int) $task['editor_id'] === (int) $who['id'];
        $status = $task['status'];

        return match ($action) {
            'approve' => $admin && $status === 'proposed',
            'assign' => $admin && in_array($status, self::WORKING, true),
            'start' => $doer && $status === 'approved',
            // Si se olvidó de "Empezar", puede entregar igual
            'deliver' => $doer && in_array($status, self::WORKING, true),
            'review' => $admin && $status === 'review' && ($task['pending'] ?? false),
            'publish' => $doer && $status === 'ready',
            'discard' => $admin && !in_array($status, ['testing', 'tested', 'discarded'], true),
            default => false,
        };
    }

    /** ¿Es un enlace que se puede abrir (https)? La vista previa de Shopify, o la página publicada. */
    public static function validUrl(?string $url): bool
    {
        $url = trim((string) $url);
        $p = parse_url($url);

        return $p !== false && ($p['scheme'] ?? '') === 'https' && !empty($p['host']) && str_contains($p['host'], '.')
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /** ¿La primera entrega llegó dentro del plazo (hasta el final de ese día)? null si falta una de las dos. */
    public static function onTime(?string $deliveredAt, ?string $dueDate): ?bool
    {
        return !$deliveredAt || !$dueDate ? null : substr($deliveredAt, 0, 10) <= substr($dueDate, 0, 10);
    }

    /**
     * Lo pendiente de quien mira, para el número del menú: lo suyo por hacer (con lo devuelto) y lo suyo por publicar; el
     * dueño, además, lo que espera su revisión.
     *
     * @param array{id: int, role: ?string} $who
     * @param iterable<array{status: string, editor_id: ?int, pending?: bool}> $tasks
     * @return array{todo: int, to_publish: int, to_review: int, total: int}
     */
    public static function pending(array $who, iterable $tasks): array
    {
        $out = ['todo' => 0, 'to_publish' => 0, 'to_review' => 0];
        foreach ($tasks as $t) {
            $mine = $t['editor_id'] !== null && (int) $t['editor_id'] === (int) $who['id'];
            if ($mine && in_array($t['status'], self::WORKING, true)) {
                $out['todo']++;
            }
            if ($mine && $t['status'] === 'ready') {
                $out['to_publish']++;
            }
            if (self::can('review', $who, $t)) {
                $out['to_review']++;
            }
        }

        return $out + ['total' => array_sum($out)];
    }

    /** El tipo de encargo que sugiere lo que dicen los números de una landing (LandingRules). */
    public static function kindFor(array $findingKeys): string
    {
        return match (true) {
            (bool) array_intersect($findingKeys, ['slow', 'no_pixel', 'visit_low']) => 'speed',
            (bool) array_intersect($findingKeys, ['angle_mismatch', 'beaten']) => 'angle',
            in_array('angle_weak', $findingKeys, true) => 'offer',
            default => 'angle',
        };
    }
}
