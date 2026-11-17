<?php

namespace App\Services\Meta\Upload;

/**
 * `.155`: lo que "Aprobar y subir" le pide a Meta (MetaUploader). El de verdad (MetaUploadGateway) lee con MetaClient y
 * escribe con MetaWriter; el simulado (FakeUploadGateway) solo existe en esta PC, para probar sin Meta.
 */
interface UploadGateway
{
    /** El creativo de un anuncio: su página, su Instagram y su botón (object_story_spec y asset_feed_spec). */
    public function creativeStory(string $creativeId): array;

    public function videoFromUrl(string $act, string $url, string $name): string;

    public function videoFromFile(string $act, string $bytes, string $fileName, string $name): string;

    /** @return array{state: string, note: ?string} ready, processing o error */
    public function videoState(string $videoId): array;

    /** La miniatura que eligió Meta para el video (el anuncio la necesita). */
    public function videoThumbnail(string $videoId): ?string;

    public function image(string $act, string $bytes, string $name): string;

    /** @return list<array{id: string, name: string}> los ad sets de una campaña con ese nombre */
    public function adsetsNamed(string $campaignId, string $name): array;

    public function copyAdset(string $adsetId, string $campaignId): string;

    public function updateAdset(string $adsetId, array $fields): void;

    public function creative(string $act, array $spec): string;

    public function ad(string $act, array $fields): string;
}
