<?php

namespace App\Services\Meta\Upload;

use App\Models\MetaConnection;
use App\Services\Meta\MetaClient;

/** `.155`: el de verdad: lee con MetaClient (solo lectura) y escribe con MetaWriter (solo crea). */
final class MetaUploadGateway implements UploadGateway
{
    public function __construct(private readonly MetaClient $reader, private readonly MetaWriter $writer)
    {
    }

    /** ¿Meta simulado? Solo en esta PC (APP_ENV=local) y con META_FAKE_UPLOADS. */
    public static function simulated(): bool
    {
        return config('services.meta.fake_uploads') && app()->environment('local');
    }

    /** El de verdad o, si es simulado, el simulado. */
    public static function for(MetaConnection $connection): UploadGateway
    {
        if (self::simulated()) {
            return new FakeUploadGateway();
        }

        return new self(MetaClient::for($connection), MetaWriter::for($connection));
    }

    public function creativeStory(string $creativeId): array
    {
        return $this->reader->get($creativeId, ['fields' => 'object_story_spec,asset_feed_spec']);
    }

    public function videoFromUrl(string $act, string $url, string $name): string
    {
        return $this->writer->videoFromUrl($act, $url, $name);
    }

    public function videoFromFile(string $act, string $bytes, string $fileName, string $name): string
    {
        return $this->writer->videoFromFile($act, $bytes, $fileName, $name);
    }

    public function videoState(string $videoId): array
    {
        return UploadRules::videoState((array) ($this->reader->get($videoId, ['fields' => 'status'])['status'] ?? []));
    }

    public function videoThumbnail(string $videoId): ?string
    {
        return UploadRules::thumbnail($this->reader->get("{$videoId}/thumbnails")['data'] ?? []);
    }

    public function image(string $act, string $bytes, string $name): string
    {
        return $this->writer->image($act, $bytes, $name);
    }

    public function adsetsNamed(string $campaignId, string $name): array
    {
        $rows = $this->reader->getAll("{$campaignId}/adsets", ['fields' => 'id,name', 'limit' => 200], 10);

        return array_values(array_map(fn ($r) => ['id' => (string) $r['id'], 'name' => (string) $r['name']],
            array_filter($rows, fn ($r) => UploadRules::sameAdset((string) ($r['name'] ?? ''), $name))));
    }

    public function copyAdset(string $adsetId, string $campaignId): string
    {
        return $this->writer->copyAdset($adsetId, $campaignId);
    }

    public function updateAdset(string $adsetId, array $fields): void
    {
        $this->writer->updateAdset($adsetId, $fields);
    }

    public function creative(string $act, array $spec): string
    {
        return $this->writer->creative($act, $spec);
    }

    public function ad(string $act, array $fields): string
    {
        return $this->writer->ad($act, $fields);
    }
}
