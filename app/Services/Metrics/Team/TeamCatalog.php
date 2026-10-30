<?php

namespace App\Services\Metrics\Team;

/**
 * El catálogo de roles de la sección Equipo del panel del dueño. PHP puro.
 *
 * Cada rol del sistema tiene que estar en una de estas tres listas:
 * - ROLES: los que se miden (cada uno con su clase);
 * - UNMEASURED: los que el dueño decidió no medir, con cuándo lo decidió;
 * - NOT_PEOPLE: los que no son personas del equipo (el dueño y el usuario de la integración con Shopify).
 *
 * Si se crea un rol nuevo (por ejemplo, Editor) y nadie le define sus KPIs, el panel lo muestra con un aviso: así no se
 * olvida. Para medirlo: escribir su clase (como SellerKpis) y agregarla a ROLES.
 */
final class TeamCatalog
{
    /** @var array<int, class-string<TeamRole>> en el orden de los botones */
    public const ROLES = [SellerKpis::class, LeaderKpis::class, AgencyKpis::class, RiderKpis::class, OperatorKpis::class, EditorKpis::class];

    public const UNMEASURED = [
        'Gerente' => 'Decidiste no medirlo por ahora (29/10/2026).',
        'Master' => 'Decidiste no medirlo por ahora (29/10/2026).',
    ];

    public const NOT_PEOPLE = ['Admin', 'Shopify'];

    /** @return TeamRole[] por clave */
    public static function roles(): array
    {
        $out = [];
        foreach (self::ROLES as $class) {
            $role = new $class();
            $out[$role->key()] = $role;
        }

        return $out;
    }

    public static function find(string $key): ?TeamRole
    {
        return self::roles()[$key] ?? null;
    }

    /**
     * Los roles del sistema que nadie mide todavía (aviso) y los que el dueño decidió no medir.
     *
     * @param string[] $systemRoles roles.description
     * @return array{missing: string[], unmeasured: array<int, array{role: string, note: string}>}
     */
    public static function coverage(array $systemRoles): array
    {
        $measured = array_merge(...array_map(fn (TeamRole $r) => $r->systemRoles(), array_values(self::roles())));
        $missing = [];
        $unmeasured = [];
        foreach (array_unique($systemRoles) as $role) {
            if (in_array($role, $measured, true) || in_array($role, self::NOT_PEOPLE, true)) {
                continue;
            }
            if (isset(self::UNMEASURED[$role])) {
                $unmeasured[] = ['role' => $role, 'note' => self::UNMEASURED[$role]];
            } else {
                $missing[] = $role;
            }
        }

        return ['missing' => array_values($missing), 'unmeasured' => $unmeasured];
    }
}
