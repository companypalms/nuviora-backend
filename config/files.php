<?php

/*
 * `.90`: dónde se guardan los archivos de los creativos (las piezas de los editores, las fotos y la estrategia de cada
 * producto). "r2" = Cloudflare R2; "local" = la carpeta privada de esta PC (para probar); "off" = apagado: las entregas
 * siguen con el enlace de Google Drive. Sin FILES_DRIVER: "r2" si están las 4 llaves de R2, "local" en la PC de pruebas
 * y "off" en el servidor (nunca se llena el disco del servidor sin querer).
 */
return [
    'driver' => env('FILES_DRIVER'),

    'r2' => [
        'account' => env('R2_ACCOUNT_ID'),
        'key' => env('R2_ACCESS_KEY_ID'),
        'secret' => env('R2_SECRET_ACCESS_KEY'),
        'bucket' => env('R2_BUCKET'),
    ],
];
