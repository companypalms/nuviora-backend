<?php

namespace App\Services\Meta\Videos;

use App\Models\MetaAdAccount;
use App\Models\MetaVideoTranscript;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\MetaClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Transcribe los videos de los creativos ganadores (`.55`, decisión del dueño del 2026-10-31: solo los que ganaron,
 * `winner` y `fatigued`). Cada día (meta:transcribe, 07:30), los que faltan, hasta un tope:
 * 1. Meta da el enlace del video (`/{video_id}?fields=source`, solo lectura, con la misma conexión de la sincronización).
 * 2. Se baja a un archivo temporal (OpenAI acepta hasta 25 MB) y se borra al terminar.
 * 3. OpenAI lo pasa a texto, en español, con los tiempos de cada tramo si los da (para el hook); si no, solo el texto.
 * Sin permiso, muy grande o con error, queda anotado y se sigue con los demás. Nada se cambia en Meta.
 */
class VideoTranscriber
{
    public const WINNERS = ['winner', 'fatigued'];

    public const DAILY_LIMIT = 40;

    public const URL = 'https://api.openai.com/v1/audio/transcriptions';

    public const PROMPT = 'Anuncio de video en español de Venezuela de una tienda online que vende productos con pago contra entrega: el gancho, el problema, el producto, la oferta y el llamado a comprar.';

    /** @var array<int, MetaClient> */
    private array $clients = [];

    /** @return array{done: int, no_access: int, too_big: int, failed: int, pending: int, skipped: ?string} */
    public function run(int $limit = self::DAILY_LIMIT): array
    {
        $out = ['done' => 0, 'no_access' => 0, 'too_big' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => null];
        $todo = $this->pending();
        $out['pending'] = count($todo);
        if (!config('services.openai.key')) {
            $out['skipped'] = 'La IA no está configurada en este servidor (falta OPENAI_API_KEY): no se transcribe nada.';

            return $out;
        }
        $left = max(0, $limit - MetaVideoTranscript::whereDate('attempted_at', now()->toDateString())->count());
        foreach (array_slice($todo, 0, $left) as $v) {
            $row = $this->one($v['video_id'], $v['account_id'], $v['row']);
            $out[$row->status]++;
        }

        return $out;
    }

    /**
     * Los videos de los ganadores que toca transcribir hoy (los nuevos y los que se reintentan), de los creativos que
     * ganaron hace menos primero.
     *
     * @return array<int, array{video_id: string, account_id: ?int, row: ?MetaVideoTranscript}>
     */
    public function pending(): array
    {
        $videos = DB::table('meta_ads as a')->join('meta_creatives as c', 'c.id', '=', 'a.meta_creative_ref_id')
            ->whereIn('c.status', self::WINNERS)->whereNotNull('a.video_id')->where('a.video_id', '<>', '')
            ->groupBy('a.video_id')->selectRaw('a.video_id, MIN(a.meta_ad_account_id) AS account_id, MAX(c.status_changed_at) AS won_at')
            ->orderByDesc('won_at')->get();
        $rows = MetaVideoTranscript::whereIn('video_id', $videos->pluck('video_id')->map(fn ($v) => (string) $v))->get()->keyBy('video_id');
        $now = now();
        $out = [];
        foreach ($videos as $v) {
            $row = $rows[(string) $v->video_id] ?? null;
            if (TranscriptRules::due($row?->status, (int) ($row?->attempts ?? 0), $row?->attempted_at, $now)) {
                $out[] = ['video_id' => (string) $v->video_id, 'account_id' => $v->account_id !== null ? (int) $v->account_id : null, 'row' => $row];
            }
        }

        return $out;
    }

    private function one(string $videoId, ?int $accountId, ?MetaVideoTranscript $row): MetaVideoTranscript
    {
        $row ??= new MetaVideoTranscript(['video_id' => $videoId, 'attempts' => 0]);
        $row->fill(['meta_ad_account_id' => $accountId, 'attempts' => (int) $row->attempts + 1, 'attempted_at' => now(), 'error' => null]);
        $file = null;
        try {
            $source = $this->source($videoId, $accountId);
            if ($source === null) {
                return $this->save($row, MetaVideoTranscript::NO_ACCESS, 'Meta no dio el enlace para bajar el video (permiso de la página o video borrado).');
            }
            $file = tempnam(sys_get_temp_dir(), 'nuv_video_');
            $download = Http::timeout(90)->withOptions(['sink' => $file])->get($source);
            if ($download->failed()) {
                return $this->save($row, MetaVideoTranscript::FAILED, 'No se pudo bajar el video de Meta (' . $download->status() . ').');
            }
            clearstatcache(true, $file);
            if ((int) filesize($file) === 0 && $download->body() !== '') {
                file_put_contents($file, $download->body());
            }
            clearstatcache(true, $file);
            $size = (int) filesize($file);
            if ($size === 0) {
                return $this->save($row, MetaVideoTranscript::FAILED, 'El video bajó vacío.');
            }
            $row->size_bytes = $size;
            if ($size > TranscriptRules::MAX_BYTES) {
                return $this->save($row, MetaVideoTranscript::TOO_BIG, 'El video pesa ' . round($size / 1048576, 1) . ' MB y OpenAI acepta hasta 25 MB.');
            }
            $t = $this->transcribe($file);
            $row->fill([
                'text' => $t['text'], 'segments' => $t['segments'] ?: null, 'model' => $t['model'],
                'hook' => TranscriptRules::hook($t['segments'], $t['text']), 'transcribed_at' => now(),
            ]);

            return $this->save($row, MetaVideoTranscript::DONE, null);
        } catch (MetaApiException $e) {
            // Un problema de toda la conexión o el límite de Meta frenan la vuelta; lo demás es de este video
            if ($e->affectsConnection() || $e->kind === MetaApiException::RATE_LIMIT) {
                $this->save($row, MetaVideoTranscript::FAILED, 'Meta: ' . mb_substr($e->getMessage(), 0, 200));
                throw $e;
            }
            $status = in_array($e->kind, [MetaApiException::PERMISSION, MetaApiException::NOT_FOUND, MetaApiException::FIELD], true)
                ? MetaVideoTranscript::NO_ACCESS : MetaVideoTranscript::FAILED;

            return $this->save($row, $status, 'Meta: ' . mb_substr($e->getMessage(), 0, 200));
        } catch (RuntimeException $e) {
            report($e);

            return $this->save($row, MetaVideoTranscript::FAILED, mb_substr($e->getMessage(), 0, 300));
        } finally {
            if ($file !== null && is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function save(MetaVideoTranscript $row, string $status, ?string $error): MetaVideoTranscript
    {
        $row->status = $status;
        $row->error = $error;
        $row->save();

        return $row;
    }

    /** El enlace temporal del video, de la conexión de su cuenta publicitaria. */
    private function source(string $videoId, ?int $accountId): ?string
    {
        $account = $accountId ? MetaAdAccount::with('connection')->find($accountId) : null;
        if (!$account?->connection) {
            return null;
        }
        $client = $this->clients[$account->id] ??= MetaClient::for($account->connection);
        $video = $client->get($videoId, ['fields' => 'source,length']);

        return is_string($video['source'] ?? null) && $video['source'] !== '' ? $video['source'] : null;
    }

    /**
     * El texto del video. Primero con los tiempos de cada tramo (para el hook); si el modelo no los da (400), solo el
     * texto.
     *
     * @return array{text: string, segments: array<int, array{start: float, end: float, text: string}>, model: string}
     */
    private function transcribe(string $path): array
    {
        $model = (string) config('services.openai.video_transcribe_model', config('services.openai.transcribe_model', 'gpt-transcribe'));
        $response = $this->send($path, $model, true);
        if ($response->status() === 400) {
            $response = $this->send($path, $model, false);
        }
        if ($response->failed()) {
            throw new RuntimeException('OpenAI respondió ' . $response->status() . ': ' . mb_strimwidth((string) ($response->json('error.message') ?? $response->body()), 0, 200));
        }
        $segments = [];
        foreach ((array) ($response->json('segments') ?? []) as $s) {
            if (is_array($s) && isset($s['start'], $s['text'])) {
                $segments[] = ['start' => round((float) $s['start'], 2), 'end' => round((float) ($s['end'] ?? $s['start']), 2), 'text' => trim((string) $s['text'])];
            }
        }

        return ['text' => trim((string) $response->json('text')), 'segments' => $segments, 'model' => $model];
    }

    private function send(string $path, string $model, bool $timestamps): Response
    {
        // Hasta 25 MB (se revisó antes): se manda el contenido, sin dejar el archivo abierto
        $video = file_get_contents($path);
        if ($video === false) {
            throw new RuntimeException('No se pudo leer el video bajado.');
        }
        $fields = [['name' => 'model', 'contents' => $model], ['name' => 'language', 'contents' => 'es'], ['name' => 'prompt', 'contents' => self::PROMPT],
            ['name' => 'response_format', 'contents' => $timestamps ? 'verbose_json' : 'json']];
        if ($timestamps) {
            $fields[] = ['name' => 'timestamp_granularities[]', 'contents' => 'segment'];
        }

        return Http::withToken((string) config('services.openai.key'))->acceptJson()->timeout(120)
            ->attach('file', $video, 'video.mp4')->post(self::URL, $fields);
    }
}
