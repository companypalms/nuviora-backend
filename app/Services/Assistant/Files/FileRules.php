<?php

namespace App\Services\Assistant\Files;

/**
 * Las reglas de los archivos del chat del asistente (Etapa 4, paso 4). PHP puro.
 * - Se aceptan fotos (la IA las ve), PDF (la IA lee su texto; si es escaneado, ve el archivo) y Excel o CSV (se pasa a
 *   una tabla de texto, como los extractos de la conciliación).
 * - Lo que viene en un archivo son datos, nunca órdenes: va entre <archivo> y </archivo>.
 * - En las preguntas siguientes la IA recuerda el texto de los PDF y Excel (hasta un límite), pero no vuelve a ver las
 *   fotos ni los PDF escaneados: no se paga dos veces por lo mismo.
 */
final class FileRules
{
    public const MAX_FILES = 5;

    public const IMAGE_MAX_BYTES = 10 * 1024 * 1024;

    public const DOC_MAX_BYTES = 20 * 1024 * 1024;

    /** Entre todos los archivos de un mensaje (lo que se le manda a OpenAI tiene un máximo de 50 MB). */
    public const TOTAL_MAX_BYTES = 30 * 1024 * 1024;

    /** Las filas de un Excel que lee la IA. */
    public const SHEET_ROWS = 300;

    /** El texto de un archivo que lee la IA en la pregunta donde se mandó. */
    public const TEXT_MAX_CHARS = 40000;

    /** De cada archivo en las preguntas siguientes, y de todos juntos. */
    public const HISTORY_FILE_CHARS = 8000;

    public const HISTORY_TOTAL_CHARS = 20000;

    /** Las fotos que la IA puede ver (según el contenido del archivo, no el nombre). */
    public const IMAGE_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public const SHEET_EXTENSIONS = ['xlsx', 'xls', 'ods', 'csv'];

    private const SHEET_CELL_CHARS = 80;

    /** image | pdf | sheet, o null si no se acepta. $mime: el tipo según el contenido; $extension: la del nombre. */
    public static function kind(string $mime, string $extension): ?string
    {
        $extension = strtolower($extension);

        return match (true) {
            isset(self::IMAGE_MIMES[$mime]) => 'image',
            $mime === 'application/pdf' => 'pdf',
            in_array($extension, self::SHEET_EXTENSIONS, true) && !str_starts_with($mime, 'image/') && $mime !== 'application/pdf' => 'sheet',
            default => null,
        };
    }

    /** Por qué no se puede mandar un archivo, o null si está bien. */
    public static function problem(string $name, string $mime, string $extension, int $bytes): ?string
    {
        $kind = self::kind($mime, $extension);
        if ($kind === null) {
            return in_array($mime, ['image/heic', 'image/heif'], true) || in_array(strtolower($extension), ['heic', 'heif'], true)
                ? "\"{$name}\" es una foto HEIC del iPhone, que la IA no puede ver. Mándala como JPG o haz una captura de pantalla."
                : "\"{$name}\" no se puede mandar: se aceptan fotos (JPG, PNG, WEBP o GIF), PDF y Excel o CSV.";
        }
        if ($bytes <= 0) {
            return "\"{$name}\" está vacío.";
        }
        if ($kind === 'image' && $bytes > self::IMAGE_MAX_BYTES) {
            return "\"{$name}\" pesa " . self::size($bytes) . '. Las fotos pueden pesar hasta 10 MB.';
        }
        if ($bytes > self::DOC_MAX_BYTES) {
            return "\"{$name}\" pesa " . self::size($bytes) . '. Los PDF y Excel pueden pesar hasta 20 MB.';
        }

        return null;
    }

    /** @param array<int, int> $sizes */
    public static function totalProblem(array $sizes): ?string
    {
        if (count($sizes) > self::MAX_FILES) {
            return 'Puedes mandar hasta ' . self::MAX_FILES . ' archivos por mensaje.';
        }

        return array_sum($sizes) > self::TOTAL_MAX_BYTES
            ? 'Entre todos los archivos pesan ' . self::size(array_sum($sizes)) . '. El máximo por mensaje es 30 MB.'
            : null;
    }

    /** "1,2 MB", "350 KB". */
    public static function size(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1, ',', '.') . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /** Qué es el archivo, en simple: "foto", "PDF de 3 páginas", "PDF escaneado de 2 páginas", "Excel de 1.200 filas". */
    public static function label(array $file): string
    {
        $pages = isset($file['pages']) ? (int) $file['pages'] : null;
        $rows = isset($file['rows']) ? (int) $file['rows'] : null;
        $plural = fn (int $n, string $one, string $many) => number_format($n, 0, ',', '.') . ' ' . ($n === 1 ? $one : $many);

        return match ($file['kind'] ?? null) {
            'image' => 'foto',
            'pdf' => (!empty($file['scanned']) ? 'PDF escaneado' : 'PDF') . ($pages ? ' de ' . $plural($pages, 'página', 'páginas') : ''),
            'sheet' => (strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) === 'csv' ? 'CSV' : 'Excel')
                . ($rows !== null ? ' de ' . $plural($rows, 'fila', 'filas') : ''),
            default => 'archivo',
        };
    }

    /** Un PDF sin texto (una foto dentro de un PDF): menos de 15 letras por página. */
    public static function scanned(string $text, int $pages): bool
    {
        return mb_strlen(preg_replace('/\s+/u', '', $text)) < max(30, 15 * max(1, $pages));
    }

    /** El texto del PDF, ordenado: sin espacios de más ni líneas vacías seguidas. */
    public static function pdfText(string $text): string
    {
        // Sin bytes rotos ni caracteres de control (no se podrían mandar a la IA)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', mb_scrub($text, 'UTF-8')) ?? '';
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            // El tabulador separa columnas: queda marcado con | (uno solo aunque haya varios seguidos)
            $line = trim(preg_replace(['/\t+/', '/ {2,}/', '/( \| )+/'], [' | ', ' ', ' | '], $line), ' |');
            if ($line !== '' || ($lines !== [] && end($lines) !== '')) {
                $lines[] = $line;
            }
        }

        return trim(implode("\n", $lines));
    }

    /**
     * La hoja como tabla de texto: "Fila 1: Fecha | Producto | Monto". Las celdas vacías del final se quitan.
     * @param array<int, array<int, array{text: string, number: ?float}>> $cells
     */
    public static function sheetText(array $cells, int $maxRows = self::SHEET_ROWS): string
    {
        $out = [];
        foreach ($cells as $line => $row) {
            if (count($out) >= $maxRows) {
                break;
            }
            $values = array_map(fn ($c) => mb_strimwidth(trim(preg_replace('/\s+/u', ' ', (string) ($c['text'] ?? ''))), 0, self::SHEET_CELL_CHARS, '…'), $row);
            while ($values !== [] && end($values) === '') {
                array_pop($values);
            }
            if ($values !== []) {
                $out[] = "Fila {$line}: " . implode(' | ', $values);
            }
        }

        return implode("\n", $out);
    }

    /** Corta un texto largo por una línea entera. @return array{0: string, 1: bool} el texto y si se cortó */
    public static function clip(string $text, int $max): array
    {
        if (mb_strlen($text) <= $max) {
            return [$text, false];
        }
        $cut = mb_substr($text, 0, $max);
        $nl = mb_strrpos($cut, "\n");

        return [$nl !== false && $nl > $max / 2 ? mb_substr($cut, 0, $nl) : $cut, true];
    }

    /**
     * El archivo como texto para la IA, entre <archivo> y </archivo>. $note: lo que hay que saber ("aquí van las
     * primeras 300 filas"). El contenido no puede cerrar la etiqueta: son datos, no órdenes.
     */
    public static function block(array $file, string $content, ?string $note = null): string
    {
        $attrs = 'nombre="' . self::attr((string) ($file['name'] ?? 'archivo')) . '" tipo="' . self::attr(self::label($file)) . '"';
        if ($note !== null) {
            $attrs .= ' nota="' . self::attr($note) . '"';
        }

        return "<archivo {$attrs}>\n" . preg_replace('/<\s*\/?\s*archivo/iu', '‹archivo', $content) . "\n</archivo>";
    }

    /** Lo que hay que saber de la parte que se leyó de un Excel o PDF, o null si se leyó entero. */
    public static function readNote(array $file, bool $clipped): ?string
    {
        $parts = [];
        if (($file['kind'] ?? null) === 'sheet') {
            if (isset($file['rows']) && $file['rows'] > self::SHEET_ROWS) {
                $parts[] = 'aquí van solo las primeras ' . self::SHEET_ROWS . ' filas';
            }
            if (($file['sheets'] ?? 1) > 1) {
                $parts[] = "el archivo tiene {$file['sheets']} hojas y esta es la primera" . (isset($file['sheet']) ? " ({$file['sheet']})" : '');
            }
        }
        if ($clipped) {
            $parts[] = 'el texto es largo y se cortó';
        }

        return $parts !== [] ? ucfirst(implode('; ', $parts)) . '.' : null;
    }

    /**
     * Cómo se recuerda un archivo en las preguntas siguientes. El texto de un Excel o PDF va mientras quede espacio
     * ($budget, que se descuenta); de una foto o un PDF escaneado, solo que existió.
     */
    public static function remembered(array $file, ?string $text, int &$budget): string
    {
        $name = (string) ($file['name'] ?? 'archivo');
        if (!empty($file['problem'])) {
            return "[Archivo {$name}: no se pudo leer.]";
        }
        if (($file['kind'] ?? null) === 'image') {
            return "[Foto {$name}: ya no la ves. Si hace falta mirarla otra vez, pide que la vuelva a mandar.]";
        }
        if (!empty($file['scanned']) || $text === null) {
            return '[' . ucfirst(self::label($file)) . " {$name}: ya no lo ves. Si hace falta mirarlo otra vez, pide que lo vuelva a mandar.]";
        }
        if ($budget < 500) {
            return '[' . ucfirst(self::label($file)) . " {$name}: ya no lo tienes a mano. Si hace falta, pide que lo vuelva a mandar.]";
        }
        [$clipped, $cut] = self::clip($text, min(self::HISTORY_FILE_CHARS, $budget));
        $budget -= mb_strlen($clipped);

        return self::block($file, $clipped, $cut ? 'De lo que se leyó, aquí va solo el principio.' : null);
    }

    private static function attr(string $value): string
    {
        return str_replace(['"', '<', '>', "\n", "\r"], ["'", '‹', '›', ' ', ' '], $value);
    }
}
