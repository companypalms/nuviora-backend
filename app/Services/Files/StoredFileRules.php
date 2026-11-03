<?php

namespace App\Services\Files;

use Illuminate\Support\Str;

/**
 * `.90`: qué archivos se pueden subir al almacén y cómo se llaman. PHP puro.
 * - una pieza (lo que entrega el editor): un video (mp4, mov o webm, hasta 1 GB) o una imagen (jpg, png o webp, hasta
 *   30 MB), con el nombre exacto de su anuncio ("C0457 POSP.mp4");
 * - una foto del producto: una imagen;
 * - el documento de estrategia del producto: pdf, Word o texto (hasta 50 MB). Solo lo ve el dueño.
 */
final class StoredFileRules
{
    public const KINDS = ['piece' => 'Pieza', 'photo' => 'Foto del producto', 'strategy' => 'Documento de estrategia'];

    public const VIDEOS = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm'];

    public const IMAGES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public const DOCUMENTS = ['application/pdf' => 'pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword' => 'doc', 'text/plain' => 'txt', 'text/markdown' => 'md'];

    public const VIDEO_MAX = 1024 * 1024 * 1024;

    public const IMAGE_MAX = 30 * 1024 * 1024;

    public const DOCUMENT_MAX = 50 * 1024 * 1024;

    /** Lo que acepta cada clase de archivo. */
    private const ACCEPTS = ['piece' => ['video', 'image'], 'photo' => ['image'], 'strategy' => ['document']];

    /** video, image, document u other. */
    public static function type(string $mime): string
    {
        $mime = strtolower($mime);

        return match (true) {
            isset(self::VIDEOS[$mime]) => 'video',
            isset(self::IMAGES[$mime]) => 'image',
            isset(self::DOCUMENTS[$mime]) => 'document',
            default => 'other',
        };
    }

    /** El tipo por la extensión, cuando el navegador no lo dice (pasa con .mov y .md en Windows). */
    public static function mime(string $mime, string $name): string
    {
        $mime = strtolower(trim($mime));
        if (self::type($mime) !== 'other') {
            return $mime;
        }
        $byExtension = array_flip(self::VIDEOS + self::IMAGES + self::DOCUMENTS) + ['jpeg' => 'image/jpeg', 'm4v' => 'video/mp4'];

        return $byExtension[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? $mime;
    }

    /** Por qué no se puede subir, o null si se puede. */
    public static function problem(string $kind, string $mime, int $size): ?string
    {
        $type = self::type($mime);
        $accepts = self::ACCEPTS[$kind] ?? [];
        if (!in_array($type, $accepts, true)) {
            return match ($kind) {
                'piece' => 'Sube el video (mp4, mov o webm) o la imagen (jpg, png o webp) de la pieza.',
                'photo' => 'Las fotos van en jpg, png o webp.',
                default => 'El documento de estrategia va en pdf, Word o texto.',
            };
        }
        if ($size <= 0) {
            return 'El archivo está vacío.';
        }
        $max = ['video' => self::VIDEO_MAX, 'image' => self::IMAGE_MAX, 'document' => self::DOCUMENT_MAX][$type];
        if ($size > $max) {
            return 'Es muy grande: hasta ' . self::mb($max) . ' (' . ['video' => 'un video', 'image' => 'una imagen', 'document' => 'un documento'][$type] . ').';
        }

        return null;
    }

    public static function extension(string $mime, string $name): string
    {
        return (self::VIDEOS + self::IMAGES + self::DOCUMENTS)[strtolower($mime)] ?? (strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin');
    }

    /**
     * Su llave en el almacén, sin espacios ni acentos: "creativos/p12/B0110/C0457-POSP-v2-ab12cd34.mp4",
     * "productos/p12/fotos/ab12cd34ef56.jpg".
     */
    public static function path(string $kind, ?int $productId, ?string $batch, string $name, ?int $version, string $ext): string
    {
        $slug = fn (?string $v) => trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii((string) $v)), '-');
        $p = 'p' . ($productId ?? 0);

        return match ($kind) {
            'piece' => "creativos/{$p}/" . ($slug($batch) ?: 'B') . '/' . ($slug(pathinfo($name, PATHINFO_FILENAME)) ?: 'pieza')
                . ($version ? "-v{$version}" : '') . '-' . Str::lower(Str::random(8)) . ".{$ext}",
            'photo' => "productos/{$p}/fotos/" . Str::lower(Str::random(12)) . ".{$ext}",
            default => "productos/{$p}/estrategia/" . (($slug(pathinfo($name, PATHINFO_FILENAME)) ?: 'estrategia') . '-' . Str::lower(Str::random(8))) . ".{$ext}",
        };
    }

    public static function mb(int $bytes): string
    {
        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 1) . ' GB' : round($bytes / 1024 ** 2) . ' MB';
    }
}
