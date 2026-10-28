<?php

namespace App\Http\Controllers;

/**
 * La descarga de la app Nuviora Rider, sin iniciar sesión: el rider todavía no la tiene instalada. El APK no va en Git
 * (pesa ~100 MB): se copia a mano en storage/app/apps/ de cada servidor, con un JSON al lado con la versión. Cada
 * servidor sirve el suyo (el de pruebas apunta a pruebas; el de producción, a producción).
 */
class RiderAppDownloadController extends Controller
{
    private const APK = 'app/apps/nuviora-rider.apk';
    private const META = 'app/apps/nuviora-rider.json';

    /** ¿Hay app para descargar, y qué versión? */
    public function info()
    {
        $apk = storage_path(self::APK);
        if (!is_file($apk)) {
            return response()->json(['status' => true, 'available' => false]);
        }
        $meta = json_decode((string) @file_get_contents(storage_path(self::META)), true) ?: [];

        return response()->json([
            'status' => true,
            'available' => true,
            'version' => $meta['version'] ?? null,
            'size_mb' => round(filesize($apk) / 1048576, 1),
            'updated_at' => date(DATE_ATOM, filemtime($apk)),
        ]);
    }

    public function download()
    {
        $apk = storage_path(self::APK);
        if (!is_file($apk)) {
            return response()->json(['status' => false, 'message' => 'La app todavía no está disponible para descargar.'], 404);
        }
        $meta = json_decode((string) @file_get_contents(storage_path(self::META)), true) ?: [];
        $name = 'Nuviora-Rider' . (isset($meta['version']) ? '-' . preg_replace('/[^0-9.]/', '', $meta['version']) : '') . '.apk';

        return response()->download($apk, $name, ['Content-Type' => 'application/vnd.android.package-archive']);
    }
}
