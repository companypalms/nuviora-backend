<?php

namespace App\Services\Meta\Videos;

use App\Models\CreativeBatch;
use App\Models\CreativeBatchPiece;
use App\Models\StoredFile;
use App\Services\Files\FileStore;
use App\Services\Files\StoredFileRules;
use App\Services\Meta\MetaApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * `.103` (el editor, 2026-11-05: "de este no sé qué creativo es el ganador y no sale el video como tal"): el video del
 * ganador de cada encargo de iterar, guardado una vez en el almacén (R2) para verlo y bajarlo en el encargo: el editor,
 * el líder y el dueño. Cada 10 minutos (meta:winner-videos), los que faltan de los encargos aprobados, en producción o
 * en revisión, hasta 5 por vuelta:
 * 1. El video del anuncio más nuevo del ganador. Si el ganador se entregó por el sistema (C0457), ya está: su pieza.
 * 2. Meta da el enlace (solo lectura, con la misma conexión que la transcripción) y se baja a un archivo temporal.
 * 3. Se guarda con el nombre del creativo ("Ganador CRE002-FLEX-FAS-BATCH005.mp4") y queda para siempre: si otro
 *    encargo itera el mismo ganador, ya está.
 * Si Meta no lo da, se vuelve a probar en 6 horas y mientras tanto el encargo lo dice. Nada se cambia en Meta. El video
 * es el anuncio tal cual: puede mostrar el precio (el dueño lo aprobó en la ficha de la .103).
 */
final class WinnerVideos
{
    public const STATUSES = ['approved', 'production', 'review'];

    public const PER_RUN = 5;

    public const RETRY_HOURS = 6;

    public function __construct(private VideoTranscriber $meta)
    {
    }

    /** Su llave en el almacén: una por video de Meta. */
    public static function path(string $videoId): string
    {
        return 'ganadores/v' . preg_replace('/[^0-9A-Za-z]+/', '', $videoId) . '.mp4';
    }

    /** El archivo de la pieza, si el ganador se entregó por el sistema (el creativo C0457 es la pieza 457). */
    public static function pieceFileId(?string $creativeCode): ?int
    {
        $id = preg_match('/^C(\d+)$/i', (string) $creativeCode, $m) ? CreativeBatchPiece::where('number', (int) $m[1])->value('file_id') : null;

        return $id !== null ? (int) $id : null;
    }

    /**
     * Para el encargo: el video guardado del ganador y, si todavía no está, por qué.
     *
     * @return array{file: ?array, note: ?string}
     */
    public static function of(int $creativeId): array
    {
        $videoId = DB::table('meta_ads')->where('meta_creative_ref_id', $creativeId)->whereNotNull('video_id')->where('video_id', '<>', '')
            ->orderByDesc('created_time')->value('video_id');
        if ($videoId === null) {
            return ['file' => null, 'note' => null]; // una imagen, o sin anuncios
        }
        $file = StoredFile::where('path', self::path((string) $videoId))->where('status', 'ready')->first();

        return match (true) {
            $file !== null => ['file' => $file->payload(), 'note' => null],
            !FileStore::on() => ['file' => null, 'note' => null],
            Cache::has(self::failedKey((string) $videoId)) => ['file' => null,
                'note' => 'Meta no dejó bajar el video de este ganador: se vuelve a intentar solo. Mientras tanto, guíate por su miniatura y lo que dice.'],
            default => ['file' => null, 'note' => 'El video del ganador se está trayendo de Meta: aparece aquí en unos minutos.'],
        };
    }

    /** @return array{saved: int, failed: int, pending: int, skipped: ?string} */
    public function run(int $limit = self::PER_RUN): array
    {
        $out = ['saved' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => null];
        $store = FileStore::current();
        if ($store === null) {
            $out['skipped'] = 'El almacén está apagado (faltan las llaves de Cloudflare R2): no se guarda ningún video.';

            return $out;
        }
        $todo = $this->pending();
        $out['pending'] = count($todo);
        foreach (array_slice($todo, 0, $limit) as $v) {
            try {
                $this->one($v, $store) ? $out['saved']++ : $out['failed']++;
            } catch (MetaApiException $e) {
                // un problema de toda la conexión o el límite de Meta: se sigue en la próxima vuelta
                $out['skipped'] = 'Meta: ' . mb_substr($e->getMessage(), 0, 200);
                break;
            }
        }

        return $out;
    }

    /**
     * Los videos que faltan: de los ganadores de los encargos abiertos, sin pieza en el sistema, sin guardar y sin un
     * intento fallido en las últimas 6 horas.
     *
     * @return list<array{creative_id: int, product_id: int, label: string, video_id: string, account_id: ?int}>
     */
    public function pending(): array
    {
        $batches = CreativeBatch::with('reference:id,label,creative_code')->whereNotNull('reference_creative_id')
            ->whereIn('status', self::STATUSES)->orderBy('id')->get(['id', 'product_id', 'reference_creative_id']);
        $out = [];
        foreach ($batches->unique('reference_creative_id') as $b) {
            if (!$b->reference || self::pieceFileId($b->reference->creative_code) !== null) {
                continue;
            }
            $ad = DB::table('meta_ads')->where('meta_creative_ref_id', $b->reference_creative_id)->whereNotNull('video_id')->where('video_id', '<>', '')
                ->orderByDesc('created_time')->first(['video_id', 'meta_ad_account_id']);
            if (!$ad || Cache::has(self::failedKey((string) $ad->video_id))
                || StoredFile::where('path', self::path((string) $ad->video_id))->where('status', 'ready')->exists()) {
                continue;
            }
            $out[] = ['creative_id' => (int) $b->reference_creative_id, 'product_id' => (int) $b->product_id,
                'label' => (string) ($b->reference->label ?: $b->reference->creative_code ?: "creativo {$b->reference_creative_id}"),
                'video_id' => (string) $ad->video_id, 'account_id' => $ad->meta_ad_account_id !== null ? (int) $ad->meta_ad_account_id : null];
        }

        return $out;
    }

    /** Lo baja de Meta y lo guarda. false: no se pudo (se vuelve a probar en 6 horas). */
    private function one(array $v, FileStore $store): bool
    {
        $tmp = null;
        try {
            $source = $this->meta->source($v['video_id'], $v['account_id']);
            if ($source === null) {
                return $this->failed($v['video_id']);
            }
            $tmp = tempnam(sys_get_temp_dir(), 'nuv_winner_');
            $download = Http::timeout(300)->withOptions(['sink' => $tmp])->get($source);
            clearstatcache(true, $tmp);
            if ((int) filesize($tmp) === 0 && $download->body() !== '') {
                file_put_contents($tmp, $download->body());
            }
            clearstatcache(true, $tmp);
            $size = (int) filesize($tmp);
            if ($download->failed() || $size === 0 || StoredFileRules::problem('winner', 'video/mp4', $size) !== null) {
                return $this->failed($v['video_id']);
            }
            $file = new StoredFile([
                'driver' => FileStore::driver(), 'path' => self::path($v['video_id']), 'mime' => 'video/mp4', 'size' => $size, 'status' => 'ready',
                'name' => mb_substr('Ganador ' . trim((string) preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $v['label'])), 0, 240) . '.mp4',
                'kind' => 'winner', 'product_id' => $v['product_id'],
            ]);
            $store->putFile($file, $tmp);
            $file->save();

            return true;
        } catch (MetaApiException $e) {
            if ($e->affectsConnection() || $e->kind === MetaApiException::RATE_LIMIT) {
                throw $e;
            }

            return $this->failed($v['video_id']);
        } catch (Throwable $e) {
            report($e);

            return $this->failed($v['video_id']);
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function failed(string $videoId): bool
    {
        Cache::put(self::failedKey($videoId), true, now()->addHours(self::RETRY_HOURS));

        return false;
    }

    private static function failedKey(string $videoId): string
    {
        return "winner-video-failed:{$videoId}";
    }
}
