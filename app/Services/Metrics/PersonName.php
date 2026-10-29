<?php

namespace App\Services\Metrics;

/**
 * El nombre de una persona en el panel del dueño: nombres y apellidos. Muchos usuarios tienen de apellido un "-" o un "."
 * (se cargaron sin apellido): eso no se muestra ("Gigi", no "Gigi -").
 */
final class PersonName
{
    public static function of(?string $names, ?string $surnames): string
    {
        $surnames = trim((string) $surnames);
        if (preg_match('/^[\s\-\.\_,]*$/u', $surnames)) {
            $surnames = '';
        }

        return trim(trim((string) $names) . ' ' . $surnames);
    }
}
