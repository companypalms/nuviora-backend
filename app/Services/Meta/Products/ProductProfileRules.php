<?php

namespace App\Services\Meta\Products;

/**
 * Las reglas de la ficha de un producto (`.64`, dueño 2026-11-01: "la IA siempre debe tener un excelente contexto del
 * producto que se está trabajando"). PHP puro.
 * - Completa = qué es, para qué sirve, cómo funciona y al menos un buyer persona con su nombre, quién es y qué le duele.
 *   Sin ficha completa, la IA no escribe encargos de ese producto (QueueProposer) ni textos de sus landings.
 * - "Lo que NO es" es lo que nunca se dice (Comprimax no es para el frío): la IA y el editor lo leen.
 * - El editor ve lo básico del producto y SOLO el buyer persona de su encargo: nunca la lista completa (ni los ángulos,
 *   ni los aprendizajes), para que no pueda llevarse la estrategia.
 */
final class ProductProfileRules
{
    public const FIELDS = [
        'what_it_is' => 'Qué es',
        'benefits' => 'Para qué sirve',
        'mechanism' => 'Cómo funciona',
        'not_this' => 'Lo que NO es y nunca se dice',
        'key_info' => 'Información clave',
        'photos_url' => 'Fotos (Drive)',
    ];

    public const PERSONA_FIELDS = [
        'name' => 'Nombre',
        'who' => 'Quién es',
        'pain' => 'Qué le duele',
        'wants' => 'Qué quiere',
        'objections' => 'Qué lo frena',
    ];

    public const REQUIRED = ['what_it_is', 'benefits', 'mechanism'];

    public const PERSONA_REQUIRED = ['name', 'who', 'pain'];

    public const MAX_PERSONAS = 8;

    public const TEXT_MAX = 3000;

    public const NAME_MAX = 80;

    public const URL_MAX = 500;

    /** Los campos de la ficha, recortados; lo vacío queda null. */
    public static function normalize(array $profile): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $k) {
            $out[$k] = self::clean($profile[$k] ?? null);
        }

        return $out;
    }

    /** Un buyer persona, recortado; lo vacío queda null. Conserva el id si viene. */
    public static function normalizePersona(array $p): array
    {
        $out = isset($p['id']) && $p['id'] !== null ? ['id' => (int) $p['id']] : [];
        foreach (array_keys(self::PERSONA_FIELDS) as $k) {
            $out[$k] = self::clean($p[$k] ?? null);
        }

        return $out;
    }

    /**
     * Lo que no se puede guardar (largos, un link que no es link, dos buyer personas con el mismo nombre, uno sin nombre,
     * demasiados).
     *
     * @param list<array> $personas los que quedan activos
     * @return list<string>
     */
    public static function problems(array $profile, array $personas): array
    {
        $problems = [];
        foreach (self::FIELDS as $k => $label) {
            $max = $k === 'photos_url' ? self::URL_MAX : self::TEXT_MAX;
            if (mb_strlen((string) ($profile[$k] ?? '')) > $max) {
                $problems[] = "\"{$label}\" es muy largo (hasta {$max} letras).";
            }
        }
        if (($profile['photos_url'] ?? null) !== null && !preg_match('~^https?://\S+$~i', $profile['photos_url'])) {
            $problems[] = 'Las fotos van como un link (por ejemplo, la carpeta de Drive): https://…';
        }
        if (count($personas) > self::MAX_PERSONAS) {
            $problems[] = 'Como mucho ' . self::MAX_PERSONAS . ' buyer personas por producto.';
        }
        $seen = [];
        foreach ($personas as $i => $p) {
            $name = $p['name'] ?? null;
            if ($name === null) {
                $problems[] = 'El buyer persona ' . ($i + 1) . ' no tiene nombre.';
                continue;
            }
            if (mb_strlen($name) > self::NAME_MAX) {
                $problems[] = "El nombre del buyer persona \"{$name}\" es muy largo (hasta " . self::NAME_MAX . ' letras).';
            }
            foreach (['who', 'pain', 'wants', 'objections'] as $k) {
                if (mb_strlen((string) ($p[$k] ?? '')) > self::TEXT_MAX) {
                    $problems[] = "En \"{$name}\", \"" . self::PERSONA_FIELDS[$k] . '" es muy largo (hasta ' . self::TEXT_MAX . ' letras).';
                }
            }
            $key = self::key($name);
            if (isset($seen[$key])) {
                $problems[] = "Hay dos buyer personas que se llaman \"{$name}\".";
            }
            $seen[$key] = true;
        }

        return $problems;
    }

    /**
     * Lo que falta para que la ficha esté completa, en palabras.
     *
     * @param list<array> $personas los activos
     * @return list<string>
     */
    public static function missing(?array $profile, array $personas): array
    {
        $missing = [];
        foreach (self::REQUIRED as $k) {
            if (($profile[$k] ?? null) === null || trim((string) $profile[$k]) === '') {
                $missing[] = self::FIELDS[$k];
            }
        }
        $full = array_filter($personas, fn ($p) => !array_filter(self::PERSONA_REQUIRED, fn ($k) => trim((string) ($p[$k] ?? '')) === ''));
        if ($full === []) {
            $missing[] = $personas === [] ? 'al menos un buyer persona' : 'un buyer persona con quién es y qué le duele';
        }

        return $missing;
    }

    public static function complete(?array $profile, array $personas): bool
    {
        return self::missing($profile, $personas) === [];
    }

    /**
     * La ficha en texto, para la IA que escribe encargos y landings. Va completa (con todos los buyer personas): la IA es
     * nuestra; lo que llega al editor lo filtra forEditor.
     */
    public static function forAi(?array $profile, array $personas): string
    {
        $lines = [];
        foreach (self::FIELDS as $k => $label) {
            if (($profile[$k] ?? null) !== null && $k !== 'photos_url') {
                $lines[] = "  · {$label}: " . self::oneLine($profile[$k]);
            }
        }
        if ($personas !== []) {
            $lines[] = '  · Buyer personas:';
            foreach ($personas as $p) {
                $lines[] = '    - ' . self::personaLine($p);
            }
        }

        return $lines === [] ? '' : implode("\n", $lines);
    }

    /** Un buyer persona en una línea: "Nombre: quién es. Le duele: … Quiere: … Lo frena: …". */
    public static function personaLine(array $p): string
    {
        $parts = [];
        foreach (['who' => null, 'pain' => 'Le duele', 'wants' => 'Quiere', 'objections' => 'Lo frena'] as $k => $label) {
            if (($p[$k] ?? null) !== null && trim((string) $p[$k]) !== '') {
                $parts[] = ($label ? "{$label}: " : '') . rtrim(self::oneLine($p[$k]), '.') . '.';
            }
        }

        return ($p['name'] ?? 'Sin nombre') . ($parts ? ': ' . implode(' ', $parts) : '');
    }

    /** Lo del producto que ve el editor: lo básico, sin buyer personas. null si no hay nada. */
    public static function forEditor(?array $profile): ?array
    {
        if ($profile === null) {
            return null;
        }
        // .69: sin precios ni montos (el editor no ve el precio del producto)
        $out = array_filter(array_map(fn ($v) => \App\Services\Meta\Queue\QueueRules::withoutPrices($v === null ? null : (string) $v),
            array_intersect_key($profile, self::FIELDS)), fn ($v) => $v !== null);

        return $out === [] ? null : $out;
    }

    /** El buyer persona de un encargo, como lo ve el editor. */
    public static function personaForEditor(?array $p): ?array
    {
        if ($p === null) {
            return null;
        }

        return array_filter(array_map(fn ($v) => \App\Services\Meta\Queue\QueueRules::withoutPrices($v === null ? null : (string) $v),
            array_intersect_key($p, self::PERSONA_FIELDS)), fn ($v) => $v !== null) ?: null;
    }

    /**
     * Lo que pide el asistente sobre la ficha guardada: los campos que vienen (no null) reemplazan a los de antes; cada
     * buyer persona se busca por su nombre (si ya existe, se cambian los campos que vienen; si no, se agrega) y los de
     * $remove se quitan.
     *
     * @param list<array> $personas los activos de ahora (con su id)
     * @param list<array> $personaChanges normalizePersona, sin id
     * @param list<string> $remove nombres
     * @return array{profile: array, personas: list<array>, removed: list<array>, unknown: list<string>} unknown: los nombres a
     *         quitar que no están en la ficha
     */
    public static function merge(?array $profile, array $personas, array $changes, array $personaChanges, array $remove): array
    {
        $out = self::normalize($profile ?? []);
        foreach (self::normalize($changes) as $k => $v) {
            if ($v !== null) {
                $out[$k] = $v;
            }
        }
        $removed = [];
        $unknown = [];
        foreach ($remove as $name) {
            $found = self::findByName($personas, $name);
            if ($found === null) {
                $unknown[] = $name;
                continue;
            }
            $removed[] = $found;
            $personas = array_values(array_filter($personas, fn ($p) => self::key((string) $p['name']) !== self::key((string) $found['name'])));
        }
        foreach ($personaChanges as $change) {
            if (($change['name'] ?? null) === null) {
                continue;
            }
            $i = array_search(self::key($change['name']), array_map(fn ($p) => self::key((string) $p['name']), $personas), true);
            if ($i === false) {
                $personas[] = $change;
                continue;
            }
            foreach (self::PERSONA_FIELDS as $k => $_) {
                if ($k !== 'name' && ($change[$k] ?? null) !== null) {
                    $personas[$i][$k] = $change[$k];
                }
            }
        }

        return ['profile' => $out, 'personas' => array_values($personas), 'removed' => $removed, 'unknown' => $unknown];
    }

    /** `.65`: un ángulo de la ficha: el código en mayúsculas (el de los nombres de Meta), su nombre, la hipótesis y su buyer persona (por nombre). */
    public static function normalizeAngle(array $a): array
    {
        return [
            'code' => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($a['code'] ?? ''))),
            'name' => self::clean($a['name'] ?? null),
            'hypothesis' => self::clean($a['hypothesis'] ?? null),
            'persona' => self::clean($a['persona'] ?? null),
        ];
    }

    /**
     * Lo que no se puede guardar de los ángulos: un código que no sirve o repetido, sin nombre, un buyer persona que no
     * está en la ficha, demasiados.
     *
     * @param list<array> $angles normalizeAngle
     * @param list<array> $personas los buyer personas que quedan en la ficha
     * @return list<string>
     */
    public static function angleProblems(array $angles, array $personas): array
    {
        $problems = [];
        if (count($angles) > TestingRules::MAX_ANGLES) {
            $problems[] = 'Como mucho ' . TestingRules::MAX_ANGLES . ' ángulos por producto.';
        }
        $seen = [];
        foreach ($angles as $i => $a) {
            $label = $a['code'] !== '' ? $a['code'] : 'El ángulo ' . ($i + 1);
            if (!preg_match('/^[A-Z0-9]{2,12}$/', $a['code'])) {
                $problems[] = "{$label}: el código va con 2 a 12 letras o números, sin espacios (es el que va en los nombres de Meta, como GINE).";
            } elseif (isset($seen[$a['code']])) {
                $problems[] = "El ángulo {$a['code']} está dos veces.";
            }
            $seen[$a['code']] = true;
            if ($a['name'] === null) {
                $problems[] = "{$label}: falta el nombre.";
            } elseif (mb_strlen($a['name']) > 120) {
                $problems[] = "{$label}: el nombre es muy largo (hasta 120 letras).";
            }
            if (mb_strlen((string) $a['hypothesis']) > 1000) {
                $problems[] = "{$label}: la hipótesis es muy larga (hasta 1000 letras).";
            }
            if ($a['persona'] !== null && self::findByName($personas, $a['persona']) === null) {
                $problems[] = "{$label}: no hay ningún buyer persona que se llame \"{$a['persona']}\" en la ficha.";
            }
        }

        return $problems;
    }

    /** `.65`: los ángulos en líneas, del más fuerte al más débil: "1. GINE · Ginecomastia → Hombre con ginecomastia". */
    public static function anglesText(array $angles): string
    {
        return implode("\n", array_map(fn ($a, $i) => ($i + 1) . ". {$a['code']}" . ($a['name'] ? " · {$a['name']}" : '') . ($a['persona'] ? " → {$a['persona']}" : ''),
            array_values($angles), array_keys(array_values($angles))));
    }

    /** `.65`: los 3 batches del testeo en palabras: "2 batches de GINE (conceptos o formatos distintos) y 1 de ABDO". */
    public static function testingText(array $codes): string
    {
        $count = array_count_values(TestingRules::slots($codes));
        if ($count === []) {
            return 'sin ángulos todavía: el testeo no puede empezar';
        }

        return TestingRules::join(array_map(fn ($code, $n) => $n . ($n === 1 ? ' batch' : ' batches') . " de {$code}" . ($n > 1 ? ' (con conceptos o formatos distintos)' : ''),
            array_keys($count), $count));
    }

    /** Un buyer persona en varias líneas, para la tarjeta y la pantalla. */
    public static function personaText(array $p): string
    {
        $lines = [];
        foreach (['who' => 'Quién es', 'pain' => 'Le duele', 'wants' => 'Quiere', 'objections' => 'Lo frena'] as $k => $label) {
            if (($p[$k] ?? null) !== null && trim((string) $p[$k]) !== '') {
                $lines[] = "{$label}: " . trim((string) $p[$k]);
            }
        }

        return implode("\n", $lines);
    }

    /** El buyer persona de una lista por su nombre (sin mayúsculas, tildes ni espacios de más), o null. */
    public static function findByName(array $personas, ?string $name): ?array
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        foreach ($personas as $p) {
            if (self::key((string) ($p['name'] ?? '')) === self::key($name)) {
                return $p;
            }
        }

        return null;
    }

    public static function key(string $name): string
    {
        $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));

        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }

    private static function clean(mixed $v): ?string
    {
        if (!is_string($v) && !is_numeric($v)) {
            return null;
        }
        $v = trim(str_replace("\r\n", "\n", (string) $v));

        return $v === '' ? null : $v;
    }

    private static function oneLine(string $v): string
    {
        return trim(preg_replace('/\s*\n+\s*/u', ' / ', $v));
    }
}
