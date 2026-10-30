<?php

namespace App\Services\Meta\Queue;

/**
 * Los editores en la cola de producción (Etapa 5, paso 5a), en PHP puro. El camino de un encargo:
 * el dueño lo aprueba (B####) → el editor líder lo asigna (a sí mismo o al otro) → el editor "Empezar" (En producción) →
 * "Entregar" con un enlace de Drive por pieza y su ficha (V01, En revisión) → el líder lo aprueba (En banca) o lo devuelve
 * con un comentario (vuelve a En producción y la próxima entrega es la V02).
 *
 * Decisiones del dueño (2026-10-30):
 * - el líder también edita, pero sus propias entregas las aprueba el dueño: no puede aprobarse a sí mismo;
 * - un enlace de Drive por pieza (de un archivo, no de una carpeta): más adelante la IA va a bajar cada archivo para
 *   subirlo a Meta con su aprobación;
 * - los editores no ven dólares (eso es del paso 5b).
 * El Admin puede todo, desde Meta Ads › Cola y banca.
 */
final class EditorRules
{
    public const LEADER = 'Editor líder';
    public const EDITOR = 'Editor de video';
    public const ROLES = [self::LEADER, self::EDITOR];
    public const ADMIN = 'Admin';

    /** Lo que se puede hacer con un encargo: asignar, empezar, entregar y revisar. */
    public const ACTIONS = ['assign', 'start', 'deliver', 'review'];

    /** Lo que se puede reasignar: lo que todavía no se entregó. */
    private const ASSIGNABLE = ['approved', 'production'];

    public static function isEditor(?string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    /**
     * ¿Puede esta persona ver este encargo? El editor, los suyos; el líder y el dueño, todos los aprobados. Nadie de los
     * editores ve lo que todavía no aprobó el dueño.
     *
     * @param array{id: int, role: ?string} $who
     * @param array{status: string, editor_id: ?int} $batch
     */
    public static function canSee(array $who, array $batch): bool
    {
        if ($who['role'] === self::ADMIN) {
            return true;
        }
        if (!self::isEditor($who['role']) || $batch['status'] === 'proposed') {
            return false;
        }

        return $who['role'] === self::LEADER || (int) $batch['editor_id'] === (int) $who['id'];
    }

    /**
     * ¿Puede hacer esto? Para "review", el encargo trae "pending_by": quién hizo la entrega que espera revisión.
     *
     * @param array{id: int, role: ?string} $who
     * @param array{status: string, editor_id: ?int, pending_by?: ?int} $batch
     */
    public static function can(string $action, array $who, array $batch): bool
    {
        $boss = $who['role'] === self::ADMIN || $who['role'] === self::LEADER;
        $mine = $batch['editor_id'] !== null && (int) $batch['editor_id'] === (int) $who['id'] && self::isEditor($who['role']);
        $pendingBy = $batch['pending_by'] ?? null;

        return match ($action) {
            'assign' => $boss && in_array($batch['status'], self::ASSIGNABLE, true),
            'start' => $mine && $batch['status'] === 'approved',
            // Si se olvidó de "Empezar", puede entregar igual
            'deliver' => $mine && in_array($batch['status'], self::ASSIGNABLE, true),
            'review' => $batch['status'] === 'review' && $pendingBy !== null
                && ($who['role'] === self::ADMIN || $who['role'] === self::LEADER && (int) $pendingBy !== (int) $who['id']),
            default => false,
        };
    }

    /** Por qué no puede revisar, cuando es su propia entrega (para decírselo en vez de un "sin permiso" seco). */
    public static function ownReviewMessage(): string
    {
        return 'Tus propias entregas las aprueba el dueño.';
    }

    /**
     * El id del archivo en un enlace de Google Drive, o null si no es el enlace de un archivo (una carpeta, otra página).
     * Acepta los que da "Compartir" y "Copiar enlace" en la computadora y en el teléfono:
     * drive.google.com/file/d/ID/view, drive.google.com/open?id=ID, drive.google.com/uc?id=ID y docs.google.com/file/d/ID.
     */
    public static function driveFileId(?string $url): ?string
    {
        $url = trim((string) $url);
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !in_array(strtolower($parts['host'] ?? ''), ['drive.google.com', 'docs.google.com'], true)) {
            return null;
        }
        $id = null;
        if (preg_match('#^/file/d/([A-Za-z0-9_-]+)#', $parts['path'] ?? '', $m)) {
            $id = $m[1];
        } elseif (in_array($parts['path'] ?? '', ['/open', '/uc'], true)) {
            parse_str($parts['query'] ?? '', $query);
            $id = is_string($query['id'] ?? null) ? $query['id'] : null;
        }

        return $id !== null && preg_match('/^[A-Za-z0-9_-]{20,}$/', $id) ? $id : null;
    }

    /** El aviso cuando el enlace no sirve, según lo que pegó. */
    public static function linkProblem(?string $url): string
    {
        return str_contains((string) $url, '/folders/')
            ? 'Es el enlace de una carpeta: pega el de cada video (en Drive, clic derecho en el archivo › Compartir › Copiar enlace).'
            : 'Pega el enlace de Google Drive del archivo (en Drive, clic derecho en el archivo › Compartir › Copiar enlace).';
    }

    /** 1 → "V01". */
    public static function version(int $n): string
    {
        return 'V' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }

    /** ¿La primera entrega llegó dentro del plazo (hasta el final de ese día)? null si falta una de las dos. */
    public static function onTime(?string $deliveredAt, ?string $dueDate): ?bool
    {
        if (!$deliveredAt || !$dueDate) {
            return null;
        }

        return substr($deliveredAt, 0, 10) <= substr($dueDate, 0, 10);
    }

    /**
     * Lo que tiene pendiente cada uno, para el número del menú:
     * - todos: lo suyo por empezar o en producción (los devueltos también);
     * - el líder, además: lo que falta asignar y lo que espera su revisión (no lo suyo, que lo aprueba el dueño).
     *
     * @param array{id: int, role: ?string} $who
     * @param iterable<array{status: string, editor_id: ?int, pending_by?: ?int}> $batches
     * @return array{todo: int, to_assign: int, to_review: int, total: int}
     */
    public static function pending(array $who, iterable $batches): array
    {
        $out = ['todo' => 0, 'to_assign' => 0, 'to_review' => 0];
        foreach ($batches as $b) {
            if ((int) $b['editor_id'] === (int) $who['id'] && in_array($b['status'], self::ASSIGNABLE, true)) {
                $out['todo']++;
            }
            if ($who['role'] === self::LEADER) {
                if ($b['status'] === 'approved' && $b['editor_id'] === null) {
                    $out['to_assign']++;
                }
                if (self::can('review', $who, $b)) {
                    $out['to_review']++;
                }
            }
        }

        return $out + ['total' => array_sum($out)];
    }
}
