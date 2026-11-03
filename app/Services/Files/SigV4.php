<?php

namespace App\Services\Files;

/**
 * `.90`: las URLs firmadas de S3 (firma "AWS Signature Version 4" en la query), que es como se habla con Cloudflare R2.
 * Con una URL firmada el navegador sube o baja un archivo directo al almacén (sin pasar por el servidor ni por su límite
 * de 50 MB), y solo durante un rato. Se firma solo el "host" y el cuerpo va sin firmar (UNSIGNED-PAYLOAD), como las URLs
 * firmadas de S3. PHP puro: sin el SDK de AWS (no agrega dependencias). Probado con el ejemplo de la documentación de AWS.
 */
final class SigV4
{
    /**
     * @param string $method GET, PUT, HEAD o DELETE
     * @param string $host "cuenta.r2.cloudflarestorage.com"
     * @param string $path "/bucket/llave" sin codificar (se codifica cada parte)
     * @param array<string, string> $query parámetros extra que también se firman (response-content-disposition…)
     */
    public static function presign(string $method, string $host, string $path, string $region, string $key, string $secret,
        int $expires, \DateTimeInterface $now, array $query = [], string $service = 's3'): string
    {
        $date = \DateTimeImmutable::createFromInterface($now)->setTimezone(new \DateTimeZone('UTC'));
        $stamp = $date->format('Ymd\THis\Z');
        $day = $date->format('Ymd');
        $scope = "{$day}/{$region}/{$service}/aws4_request";
        $query += [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$key}/{$scope}",
            'X-Amz-Date' => $stamp,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        $canonicalQuery = self::query($query);
        $canonicalPath = self::path($path);
        $request = implode("\n", [strtoupper($method), $canonicalPath, $canonicalQuery, "host:{$host}", '', 'host', 'UNSIGNED-PAYLOAD']);
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $stamp, $scope, hash('sha256', $request)]);
        $signingKey = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', $service, hash_hmac('sha256', $region, hash_hmac('sha256', $day, "AWS4{$secret}", true), true), true), true);
        $signature = hash_hmac('sha256', $toSign, $signingKey);

        return "https://{$host}{$canonicalPath}?{$canonicalQuery}&X-Amz-Signature={$signature}";
    }

    /** Cada parte de la ruta codificada (RFC 3986), con sus "/". */
    public static function path(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** Los parámetros ordenados por nombre y codificados (RFC 3986). */
    public static function query(array $query): string
    {
        $pairs = [];
        foreach ($query as $k => $v) {
            $pairs[rawurlencode((string) $k)] = rawurlencode((string) $v);
        }
        ksort($pairs, SORT_STRING);

        return implode('&', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($pairs), $pairs));
    }
}
