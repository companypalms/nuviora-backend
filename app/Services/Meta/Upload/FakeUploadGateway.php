<?php

namespace App\Services\Meta\Upload;

/**
 * `.155`: un Meta simulado, solo para probar "Aprobar y subir" en esta PC (META_FAKE_UPLOADS=true y APP_ENV=local). No
 * llama a nadie: devuelve números inventados y los videos quedan listos a la segunda pregunta.
 */
final class FakeUploadGateway implements UploadGateway
{
    public function creativeStory(string $creativeId): array
    {
        return ['object_story_spec' => ['page_id' => '100000000000001', 'instagram_user_id' => '200000000000001',
            'video_data' => ['call_to_action' => ['type' => 'SHOP_NOW']]]];
    }

    public function videoFromUrl(string $act, string $url, string $name): string
    {
        return self::id();
    }

    public function videoFromFile(string $act, string $bytes, string $fileName, string $name): string
    {
        return self::id();
    }

    public function videoState(string $videoId): array
    {
        $key = "fake-meta-video:{$videoId}";
        $seen = (int) cache()->get($key, 0) + 1;
        cache()->put($key, $seen, 3600);

        return $seen > 1 ? ['state' => 'ready', 'note' => null] : ['state' => 'processing', 'note' => null];
    }

    public function videoThumbnail(string $videoId): ?string
    {
        return 'https://example.com/miniatura.jpg';
    }

    public function image(string $act, string $bytes, string $name): string
    {
        return md5($bytes . $name);
    }

    public function adsetsNamed(string $campaignId, string $name): array
    {
        return [];
    }

    public function copyAdset(string $adsetId, string $campaignId): string
    {
        return self::id();
    }

    public function updateAdset(string $adsetId, array $fields): void
    {
    }

    public function creative(string $act, array $spec): string
    {
        return self::id();
    }

    public function ad(string $act, array $fields): string
    {
        return self::id();
    }

    private static function id(): string
    {
        return '9' . str_pad((string) random_int(0, 99999999999999), 14, '0', STR_PAD_LEFT);
    }
}
