<?php

namespace App\Services\Meta\Products;

use App\Models\ProductNote;
use App\Services\Meta\Queue\QueueRules;

/**
 * "Lo que sabemos" de cada producto (`.66`, dueño 2026-11-01: "la IA debe ser capaz de aprender de cada hipótesis
 * positiva o negativa… sabiendo qué funciona y qué no, tanto en ángulos, formatos, conceptos, buyers personas"). PHP puro.
 * - Los números los cuenta el código: por ángulo, por buyer persona y por estilo, cuántos batches testeados ganaron,
 *   perdieron o empataron (el resultado de cada batch es el de EditorRules::result). La IA solo los explica.
 * - Lo que falta probar: los ángulos de la ficha, los buyer personas y los estilos sin ningún batch testeado.
 * - `.81`: también pieza por pieza: qué probó cada una (un hook nuevo, otro formato, arreglar el medio…) y si ganó.
 * - Es del dueño: la IA lo usa para decidir, pero nunca se lo cuenta al editor.
 */
final class ProductMemoryRules
{
    public const DIMENSIONS = ['angle' => 'Ángulo', 'persona' => 'Buyer persona', 'style' => 'Estilo'];

    /** Cuántos aprendizajes de cada grupo se le pasan a la IA. */
    public const LESSONS_PER_GROUP = 3;

    /**
     * Los hechos de un producto.
     *
     * @param list<array{code: ?string, angle: ?string, angle_name: ?string, persona: ?string, style: ?string, concept: ?string,
     *     outcome: string, learning: ?string, tested_at: ?string}> $batches los testeados, del más nuevo al más viejo
     * @param list<string> $angles los ángulos de la ficha (del más fuerte al más débil)
     * @param list<string> $personas los buyer personas de la ficha
     * @param list<array{kind: string, text: string}> $notes las notas del dueño
     * @param list<array{code: ?string, test: ?string, style: ?string, outcome: string}> $pieces `.81`: las piezas con resultado
     */
    public static function facts(array $batches, array $angles, array $personas, array $notes, array $pieces = []): array
    {
        $groups = [];
        foreach (array_keys(self::DIMENSIONS) as $dim) {
            $groups[$dim] = self::group($batches, $dim);
        }
        $tried = fn (string $dim) => array_filter(array_column($groups[$dim], 'subject'));
        $pieceStyles = array_filter(array_column($pieces, 'style'));

        return [
            'tested' => count($batches),
            'totals' => ['won' => self::count($batches, 'Ganó'), 'lost' => self::count($batches, 'Perdió'), 'tie' => self::count($batches, 'Empate')],
            'groups' => $groups,
            'untested' => [
                'angle' => array_values(array_diff($angles, $tried('angle'))),
                'persona' => array_values(array_filter($personas, fn ($p) => !in_array(ProductProfileRules::key($p), array_map([ProductProfileRules::class, 'key'], $tried('persona')), true))),
                'style' => array_values(array_diff(array_keys(array_diff_key(QueueRules::STYLES, ['otro' => 1])), $tried('style'), $pieceStyles)),
            ],
            'piece_groups' => self::pieceGroups($pieces),
            'pieces' => $pieces,
            'batches' => $batches,
            'notes' => array_values($notes),
        ];
    }

    /**
     * Los batches testeados agrupados por ángulo, buyer persona o estilo: cuántos ganaron, perdieron o empataron y sus
     * aprendizajes (los más nuevos primero). Los de más ganados primero.
     *
     * @return list<array{subject: ?string, label: string, won: int, lost: int, tie: int, total: int, lessons: list<string>, codes: list<string>}>
     */
    public static function group(array $batches, string $dim): array
    {
        $out = [];
        foreach ($batches as $b) {
            $subject = $b[$dim] ?? null;
            $k = $subject ?? '';
            $out[$k] ??= ['subject' => $subject, 'label' => self::label($dim, $subject, $b), 'won' => 0, 'lost' => 0, 'tie' => 0, 'total' => 0, 'lessons' => [], 'codes' => []];
            $out[$k][match ($b['outcome']) { 'Ganó' => 'won', 'Perdió' => 'lost', default => 'tie' }]++;
            $out[$k]['total']++;
            if ($b['code']) {
                $out[$k]['codes'][] = $b['code'];
            }
            if (trim((string) ($b['learning'] ?? '')) !== '') {
                $out[$k]['lessons'][] = ($b['code'] ? "{$b['code']}: " : '') . trim($b['learning']);
            }
        }
        $out = array_values($out);
        usort($out, fn ($a, $b) => [-$a['won'], $a['lost'], $a['label']] <=> [-$b['won'], $b['lost'], $b['label']]);

        return $out;
    }

    /**
     * `.81`: las piezas agrupadas por lo que probaron ("Hook nuevo en un ganador", "Formato UGC"…): cuántas ganaron,
     * perdieron o empataron. Las de más ganadas primero.
     *
     * @return list<array{subject: string, label: string, won: int, lost: int, tie: int, total: int, lessons: list<string>, codes: list<string>}>
     */
    public static function pieceGroups(array $pieces): array
    {
        $out = [];
        foreach ($pieces as $p) {
            $label = \App\Services\Meta\Queue\PieceRules::groupLabel($p['test'] ?? null, $p['style'] ?? null);
            $out[$label] ??= ['subject' => $label, 'label' => $label, 'won' => 0, 'lost' => 0, 'tie' => 0, 'total' => 0, 'lessons' => [], 'codes' => []];
            $out[$label][match ($p['outcome']) { 'Ganó' => 'won', 'Perdió' => 'lost', default => 'tie' }]++;
            $out[$label]['total']++;
            if ($p['code'] ?? null) {
                $out[$label]['codes'][] = $p['code'];
            }
        }
        $out = array_values($out);
        usort($out, fn ($a, $b) => [-$a['won'], $a['lost'], $a['label']] <=> [-$b['won'], $b['lost'], $b['label']]);

        return $out;
    }

    /** "ganó 2 de 3 piezas (perdió 1)". */
    public static function pieceScore(array $g): string
    {
        $rest = array_filter([$g['lost'] ? "perdió {$g['lost']}" : null, $g['tie'] ? "empate en {$g['tie']}" : null]);

        return "ganó {$g['won']} de {$g['total']} " . ($g['total'] === 1 ? 'pieza' : 'piezas') . ($rest ? ' (' . implode(', ', $rest) . ')' : '');
    }

    /** Lo que sabemos en texto, para la IA que escribe el resumen. */
    public static function input(string $product, array $f): string
    {
        $lines = ["PRODUCTO: {$product}", "Batches testeados: {$f['tested']} (ganaron {$f['totals']['won']}, perdieron {$f['totals']['lost']}, empate {$f['totals']['tie']})."];
        foreach (self::DIMENSIONS as $dim => $title) {
            if ($f['groups'][$dim] === []) {
                continue;
            }
            $lines[] = '';
            $lines[] = mb_strtoupper($title) . ':';
            foreach ($f['groups'][$dim] as $g) {
                $lines[] = "- {$g['label']}: " . self::score($g);
                foreach (array_slice($g['lessons'], 0, self::LESSONS_PER_GROUP) as $l) {
                    $lines[] = "  · aprendizaje: {$l}";
                }
            }
        }
        if (($f['piece_groups'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = 'PIEZA POR PIEZA (qué probó cada una):';
            foreach ($f['piece_groups'] as $g) {
                $lines[] = "- {$g['label']}: " . self::pieceScore($g);
            }
        }
        $untested = self::untestedText($f['untested']);
        if ($untested !== '') {
            $lines[] = '';
            $lines[] = "FALTA PROBAR: {$untested}";
        }
        if ($f['notes'] !== []) {
            $lines[] = '';
            $lines[] = 'NOTAS DEL DUEÑO (lo que él sabe; vale tanto como los datos):';
            foreach ($f['notes'] as $n) {
                $lines[] = '- ' . (ProductNote::KINDS[$n['kind']] ?? $n['kind']) . ': ' . trim($n['text']);
            }
        }

        return implode("\n", $lines);
    }

    /** Lo que sabemos, para la IA que escribe los encargos: el resumen, los números y las notas del dueño. */
    public static function forWriter(array $f, ?array $summary): string
    {
        if ($f['tested'] === 0 && $f['notes'] === []) {
            return '';
        }
        $lines = [];
        foreach (['works' => 'Funciona', 'fails' => 'No funciona', 'untested' => 'Falta probar'] as $k => $title) {
            foreach ($summary[$k] ?? [] as $t) {
                $lines[] = "  · {$title}: {$t}";
            }
        }
        foreach (self::DIMENSIONS as $dim => $title) {
            foreach ($f['groups'][$dim] as $g) {
                if ($g['subject'] !== null) {
                    $lines[] = "  · {$title} {$g['label']}: " . self::score($g);
                }
            }
        }
        foreach ($f['piece_groups'] ?? [] as $g) {
            $lines[] = "  · Pieza por pieza, {$g['label']}: " . self::pieceScore($g);
        }
        foreach ($f['notes'] as $n) {
            $lines[] = '  · Nota del dueño (' . mb_strtolower(ProductNote::KINDS[$n['kind']] ?? $n['kind']) . '): ' . trim($n['text']);
        }

        return implode("\n", $lines);
    }

    /** El resumen sin IA (en la PC de pruebas o si la IA falla), con los números. */
    public static function example(array $f): array
    {
        $works = [];
        $fails = [];
        foreach (self::DIMENSIONS as $dim => $title) {
            foreach ($f['groups'][$dim] as $g) {
                if ($g['subject'] === null) {
                    continue;
                }
                if ($g['won'] > 0) {
                    $works[] = "{$title} {$g['label']}: " . self::score($g) . '.';
                } elseif ($g['lost'] === $g['total']) {
                    $fails[] = "{$title} {$g['label']}: " . self::score($g) . '.';
                }
            }
        }
        foreach ($f['piece_groups'] ?? [] as $g) {
            if ($g['won'] > 0) {
                $works[] = "{$g['label']}: " . self::pieceScore($g) . '.';
            } elseif ($g['lost'] === $g['total']) {
                $fails[] = "{$g['label']}: " . self::pieceScore($g) . '.';
            }
        }
        $untested = self::untestedText($f['untested']);

        return ['works' => $works, 'fails' => $fails, 'untested' => $untested !== '' ? [$untested . '.'] : []];
    }

    /** "ganó 2 de 3 batches (perdió 1)". */
    public static function score(array $g): string
    {
        $rest = array_filter([$g['lost'] ? "perdió {$g['lost']}" : null, $g['tie'] ? "empate en {$g['tie']}" : null]);

        return "ganó {$g['won']} de {$g['total']} " . ($g['total'] === 1 ? 'batch' : 'batches') . ($rest ? ' (' . implode(', ', $rest) . ')' : '');
    }

    /** La huella de los hechos: si cambia, el resumen se vuelve a escribir. */
    public static function hash(array $f): string
    {
        return hash('sha256', json_encode([$f['batches'], $f['notes'], $f['untested'], $f['pieces'] ?? []], JSON_UNESCAPED_UNICODE));
    }

    private static function untestedText(array $u): string
    {
        return implode('; ', array_filter([
            $u['angle'] ? 'ángulos ' . TestingRules::join($u['angle']) : null,
            $u['persona'] ? 'buyer personas ' . TestingRules::join($u['persona']) : null,
            $u['style'] ? 'estilos ' . TestingRules::join(array_map(fn ($s) => mb_strtolower(QueueRules::STYLES[$s] ?? $s), $u['style'])) : null,
        ]));
    }

    private static function label(string $dim, ?string $subject, array $b): string
    {
        return match ($dim) {
            'angle' => $subject === null ? 'Sin ángulo' : $subject . (($b['angle_name'] ?? null) ? " · {$b['angle_name']}" : ''),
            'persona' => $subject ?? 'Sin buyer persona',
            default => $subject === null ? 'Sin estilo' : (QueueRules::STYLES[$subject] ?? $subject),
        };
    }

    private static function count(array $batches, string $outcome): int
    {
        return count(array_filter($batches, fn ($b) => $b['outcome'] === $outcome));
    }
}
