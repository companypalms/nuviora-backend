<?php

namespace App\Services\Meta\Upload;

use App\Models\MetaConnection;
use App\Models\MetaUpload;
use App\Models\StoredFile;
use App\Services\Files\FileStore;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\Queue\EditorRules;
use Illuminate\Support\Facades\Http;

/**
 * `.155` "Aprobar y subir": sube un batch aprobado a su campaña, paso a paso, y guarda cada cosa que crea en Meta (con su
 * número) apenas la crea. Así, si algo falla o se corta, "Reintentar" sigue donde se quedó sin crear nada dos veces.
 * 1. Los archivos de cada pieza (el video del almacén o de Drive; las imágenes, sus bytes).
 * 2. Espera a que Meta procese los videos (hasta 30 minutos) y toma su miniatura.
 * 3. La página y el Instagram del anuncio de donde se copia el texto.
 * 4. El ad set: copia el más nuevo de la campaña, APAGADO, y le pone el nombre (B0123 FASP) y el gasto mínimo.
 * 5. Un creativo y un anuncio por pieza (C0457 FASP), dentro del ad set apagado.
 * 6. Al final prende el ad set, salvo en las primeras 5 subidas (quedan en pausa para que el dueño las revise).
 * Nada se prende antes de estar completo, y nada se borra nunca.
 */
class MetaUploader
{
    /** @param ?\Closure(MetaConnection): UploadGateway $gatewayFor */
    public function __construct(private ?\Closure $gatewayFor = null)
    {
    }

    /**
     * Avanza lo que pueda en unos segundos.
     *
     * @return string done (terminó), wait (Meta procesa los videos), again (falta, sigue ya) o failed
     */
    public function advance(MetaUpload $u, int $seconds = 55): string
    {
        $start = microtime(true);
        $late = fn () => microtime(true) - $start > $seconds;
        $account = $u->account()->with('connection')->first();
        if (!$account?->connection) {
            return $this->fail($u, 'la cuenta publicitaria ya no está conectada al sistema');
        }
        $api = $this->gatewayFor ? ($this->gatewayFor)($account->connection) : MetaUploadGateway::for($account->connection);
        $act = $account->actId();
        $plan = $u->plan;
        $made = $u->created ?? [];
        $u->forceFill(['status' => 'running', 'started_at' => $u->started_at ?? now()])->save();

        try {
            // 1. Los archivos
            foreach ($plan['ads'] as $ad) {
                if (isset($made['media'][$ad['piece_id']])) {
                    continue;
                }
                if ($late()) {
                    return $this->again($u, $made, 'files');
                }
                $this->at($u, 'files');
                $made['media'][$ad['piece_id']] = $this->media($api, $act, $ad);
                $made = $this->keep($u, $made);
            }

            // 2. Meta procesa los videos (se preguntan todos; si alguno no está, se vuelve en 30 segundos)
            $pending = null;
            foreach ($made['media'] ?? [] as $pid => $m) {
                if ($m['type'] !== 'video' || ($m['ready'] ?? false)) {
                    continue;
                }
                $state = $api->videoState($m['video_id']);
                if ($state['state'] === 'error') {
                    throw new UploadProblem('Meta no pudo procesar el video de ' . $this->code($plan, (int) $pid) . ($state['note'] ? ": {$state['note']}" : ''));
                }
                if ($state['state'] !== 'ready') {
                    $pending ??= (int) $pid;
                    continue;
                }
                $made['media'][$pid]['ready'] = true;
                $made['media'][$pid]['thumbnail'] = $api->videoThumbnail($m['video_id']);
                $made = $this->keep($u, $made);
            }
            if ($pending !== null) {
                if ($u->waiting_since && $u->waiting_since->diffInMinutes(now()) >= UploadRules::WAIT_MINUTES) {
                    throw new UploadProblem('Meta lleva más de ' . UploadRules::WAIT_MINUTES . ' minutos procesando el video de ' . $this->code($plan, $pending));
                }
                $u->forceFill(['status' => 'waiting', 'step' => 'processing', 'waiting_since' => $u->waiting_since ?? now(), 'created' => $made])->save();

                return 'wait';
            }

            // 3. La página y el Instagram del anuncio de donde se copia el texto
            if (!isset($made['source'])) {
                $made['source'] = $api->creativeStory((string) $plan['texts']['creative_id']);
                $made = $this->keep($u, $made);
            }
            if (UploadRules::identity($made['source'])['page_id'] === null) {
                throw new UploadProblem('el anuncio de donde se copia el texto no dice su página de Facebook');
            }

            // 4. El ad set, apagado
            if (!isset($made['adset_id'])) {
                $this->at($u, 'adset');
                $there = $api->adsetsNamed($plan['campaign']['meta_id'], $plan['adset_name']);
                if ($there !== []) {
                    throw new UploadProblem("ya hay un ad set «{$there[0]['name']}» en la campaña {$plan['campaign']['name']}: no se crea otro");
                }
                $made['adset_id'] = $api->copyAdset($plan['template_adset']['meta_id'], $plan['campaign']['meta_id']);
                $made = $this->keep($u, $made);
            }
            if (!($made['adset_named'] ?? false)) {
                $api->updateAdset($made['adset_id'], array_filter(['name' => $plan['adset_name'],
                    'daily_min_spend_target' => $plan['min_spend'] !== null ? UploadRules::cents((float) $plan['min_spend']) : null]));
                $made['adset_named'] = true;
                $made = $this->keep($u, $made);
            }

            // 5. Un creativo y un anuncio por pieza
            foreach ($plan['ads'] as $ad) {
                $pid = $ad['piece_id'];
                if (isset($made['ads'][$pid])) {
                    continue;
                }
                if ($late()) {
                    return $this->again($u, $made, 'ads');
                }
                $this->at($u, 'ads');
                if (!isset($made['creatives'][$pid])) {
                    $made['creatives'][$pid] = $api->creative($act, UploadRules::creativeSpec($made['source'], $made['media'][$pid], $plan['texts'], (string) $ad['name']));
                    $made = $this->keep($u, $made);
                }
                $made['ads'][$pid] = $api->ad($act, ['name' => (string) $ad['name'], 'adset_id' => $made['adset_id'],
                    'creative' => ['creative_id' => $made['creatives'][$pid]], 'status' => 'ACTIVE']);
                $made = $this->keep($u, $made);
            }

            // 6. Prenderlo (las primeras 5, no: las prende el dueño)
            if (!$u->paused && !($made['adset_on'] ?? false)) {
                $this->at($u, 'finish');
                $api->updateAdset($made['adset_id'], ['status' => 'ACTIVE']);
                $made['adset_on'] = true;
                $made = $this->keep($u, $made);
            }
        } catch (UploadProblem $e) {
            $this->keep($u, $made);

            return $this->fail($u, $e->getMessage());
        } catch (MetaApiException $e) {
            $this->keep($u, $made);

            return $this->fail($u, UploadRules::metaError($e->kind, $e->getMessage()));
        }

        $u->forceFill(['status' => 'done', 'step' => null, 'error' => null, 'finished_at' => now(), 'created' => $made])->save();
        MetaUploads::finished($u);

        return 'done';
    }

    /**
     * Sube el archivo de una pieza. Del almacén R2, Meta baja el video con su enlace firmado; del almacén de esta PC se le
     * manda; de Drive, Meta lo baja con el enlace público. Las imágenes van siempre con sus bytes.
     *
     * @return array{type: string, video_id?: string, hash?: string}
     */
    private function media(UploadGateway $api, string $act, array $ad): array
    {
        $name = (string) $ad['name'];
        if ($ad['file_id'] ?? null) {
            $file = StoredFile::find($ad['file_id']);
            $store = $file ? FileStore::for($file->driver) : null;
            if (!$file || !$store) {
                throw new UploadProblem("el archivo de {$ad['code']} ya no está en el almacén");
            }
            if ($ad['type'] === 'image') {
                return ['type' => 'image', 'hash' => $api->image($act, (string) $store->read($file), $name)];
            }
            if ($file->driver === 'r2') {
                return ['type' => 'video', 'video_id' => $api->videoFromUrl($act, $store->viewUrl($file, true), $name)];
            }

            return ['type' => 'video', 'video_id' => $api->videoFromFile($act, (string) $store->read($file), (string) $file->name, $name)];
        }
        $id = EditorRules::driveFileId($ad['link'] ?? null);
        if ($id === null) {
            throw new UploadProblem("{$ad['code']} no tiene su archivo (ni en el almacén ni en Drive)");
        }
        if ($ad['type'] === 'image') {
            $r = Http::timeout(40)->get(UploadRules::driveDownloadUrl($id));
            if ($r->failed() || str_contains(strtolower((string) $r->header('Content-Type')), 'text/html')) {
                throw new UploadProblem("no se pudo bajar la imagen de {$ad['code']} de Drive (¿es pública?)");
            }

            return ['type' => 'image', 'hash' => $api->image($act, $r->body(), $name)];
        }

        return ['type' => 'video', 'video_id' => $api->videoFromUrl($act, UploadRules::driveVideoUrl($id), $name)];
    }

    /** Guarda lo creado hasta ahora (cada cosa apenas se crea). */
    private function keep(MetaUpload $u, array $made): array
    {
        $u->forceFill(['created' => $made])->save();

        return $made;
    }

    private function at(MetaUpload $u, string $step): void
    {
        if ($u->step !== $step || $u->status !== 'running') {
            $u->forceFill(['status' => 'running', 'step' => $step])->save();
        }
    }

    private function again(MetaUpload $u, array $made, string $step): string
    {
        $u->forceFill(['status' => 'running', 'step' => $step, 'created' => $made])->save();

        return 'again';
    }

    private function fail(MetaUpload $u, string $why): string
    {
        $u->forceFill(['status' => 'failed', 'error' => $why, 'finished_at' => now()])->save();
        MetaUploads::finished($u);

        return 'failed';
    }

    private function code(array $plan, int $pieceId): string
    {
        foreach ($plan['ads'] as $ad) {
            if ((int) $ad['piece_id'] === $pieceId) {
                return (string) ($ad['code'] ?? 'una pieza');
            }
        }

        return 'una pieza';
    }
}
