<?php

namespace App\Services\Meta\Upload;

use App\Jobs\RunMetaUpload;
use App\Jobs\SyncMetaConnection;
use App\Models\CreativeBatch;
use App\Models\MetaAnalysis;
use App\Models\MetaUpload;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\MetaWorkNotification;
use App\Services\Meta\Queue\QueueRules;
use Illuminate\Validation\ValidationException;

/**
 * `.155` "Aprobar y subir" (dueño, 2026-11-17: "que me aparezcan en mis misiones diarias, yo le doy un botón de aprobar y
 * él los suba automáticamente en meta ads"):
 * - Una misión que lanza batches de la banca en campañas que ya corren trae "upload" (UrgentList): cada batch y su campaña.
 * - El dueño ve lo que se va a crear (plans) y lo aprueba (approve): una subida por batch y campaña, que hace MetaUploader
 *   por la cola "meta".
 * - Las primeras 5 quedan en pausa; después, prendidas. Con todas listas y prendidas, la misión se marca sola.
 * - El interruptor "Subir a Meta" (Integraciones › Meta Ads › Conexiones) viene apagado.
 */
class MetaUploads
{
    public static function on(): bool
    {
        return (bool) Setting::get(UploadRules::ENABLED, false);
    }

    public static function setOn(bool $on): void
    {
        Setting::set(UploadRules::ENABLED, $on);
    }

    /** ¿La próxima subida queda en pausa? (las primeras 5; las que fallaron no cuentan) */
    public static function pausedNext(): bool
    {
        return MetaUpload::where('paused', true)->where('status', '!=', 'failed')->count() < UploadRules::PAUSED_FIRST;
    }

    /**
     * Lo que se va a crear para cada batch de la misión, para verlo antes de aprobar.
     *
     * @return array{on: bool, plans: list<array>}
     */
    public function plans(MetaAnalysis $analysis, string $key, bool $checkFiles = true): array
    {
        $plans = [];
        foreach ($this->targets($analysis, $key) as $t) {
            $batch = $this->batch($t['code']);
            $plan = (new UploadPlanner())->plan($batch, $checkFiles, (string) $t['campaign'], isset($t['min']) ? (float) $t['min'] : null);
            $done = MetaUpload::where('creative_batch_id', $batch->id)->where('campaign_meta_id', (string) $t['campaign'])->first();
            if ($done && $done->status !== 'failed') {
                $plan['problems'] = ['Ya se subió (o se está subiendo) a esta campaña.'];
                $plan['ready'] = false;
            }
            $plans[] = $plan;
        }

        return ['on' => self::on(), 'plans' => $plans];
    }

    /**
     * El dueño aprobó: una subida por batch y campaña. Si a alguna le falta algo, no se sube ninguna.
     *
     * @return list<MetaUpload>
     */
    public function approve(MetaAnalysis $analysis, string $key, ?User $by): array
    {
        if (!self::on()) {
            throw ValidationException::withMessages(['upload' => '«Subir a Meta» está apagado: préndelo en Integraciones › Meta Ads › Conexiones.']);
        }
        $targets = $this->targets($analysis, $key);
        $plans = $this->plans($analysis, $key)['plans'];
        $problems = [];
        foreach ($plans as $p) {
            foreach ($p['problems'] as $problem) {
                $problems[] = ($p['batch']['code'] ?? 'El batch') . ': ' . $problem;
            }
        }
        if ($problems !== []) {
            throw ValidationException::withMessages(['upload' => array_values(array_unique($problems))]);
        }
        $out = [];
        foreach ($plans as $i => $plan) {
            // Una que falló antes sigue con lo que ya había creado (no se repite nada)
            $u = MetaUpload::firstOrNew(['creative_batch_id' => $plan['batch']['id'], 'campaign_meta_id' => (string) $targets[$i]['campaign']]);
            $u->fill([
                'meta_ad_account_id' => $plan['account']['id'], 'meta_analysis_id' => $analysis->id, 'mission_key' => $key,
                'status' => 'queued', 'step' => null, 'paused' => $u->exists ? $u->paused : self::pausedNext(), 'plan' => $plan,
                'created' => $u->exists ? $u->created : null, 'error' => null, 'attempts' => $u->attempts + 1, 'requested_by' => $by?->id,
                'started_at' => null, 'waiting_since' => null, 'finished_at' => null,
            ])->save();
            RunMetaUpload::dispatch($u->id);
            $out[] = $u;
        }

        return $out;
    }

    /** "Reintentar": sigue donde se quedó (lo creado se guarda y no se repite). */
    public function retry(MetaUpload $u): MetaUpload
    {
        if (!self::on()) {
            throw ValidationException::withMessages(['upload' => '«Subir a Meta» está apagado: préndelo en Integraciones › Meta Ads › Conexiones.']);
        }
        if (!$u->isOpen() && $u->status !== 'failed') {
            throw ValidationException::withMessages(['upload' => 'Esta subida ya terminó.']);
        }
        if ($u->isOpen() && !self::stuck($u)) {
            throw ValidationException::withMessages(['upload' => 'Esta subida sigue trabajando.']);
        }
        $u->forceFill(['status' => 'queued', 'error' => null, 'attempts' => $u->attempts + 1, 'waiting_since' => null, 'finished_at' => null])->save();
        RunMetaUpload::dispatch($u->id);

        return $u;
    }

    /** Las subidas de un análisis, para las misiones. @return list<array> */
    public function view(MetaAnalysis $analysis): array
    {
        return MetaUpload::where('meta_analysis_id', $analysis->id)->orderBy('id')->get()->map(fn (MetaUpload $u) => self::present($u))->all();
    }

    public static function present(MetaUpload $u): array
    {
        $plan = $u->plan ?? [];
        $made = $u->created ?? [];
        $count = ['files' => count($plan['ads'] ?? []), 'files_done' => count($made['media'] ?? []),
            'ads' => count($plan['ads'] ?? []), 'ads_done' => count($made['ads'] ?? [])];
        $stuck = self::stuck($u);

        return [
            'id' => $u->id, 'key' => $u->mission_key, 'batch' => $plan['batch']['code'] ?? null, 'campaign' => $plan['campaign']['name'] ?? null,
            'adset' => $plan['adset_name'] ?? null, 'status' => $u->status, 'paused' => $u->paused,
            'text' => $stuck ? 'Parece trabada (más de 10 minutos sin avanzar): «Reintentar» sigue donde se quedó.'
                : UploadRules::progress($u->status, $u->step, $count, $u->paused, (string) ($plan['adset_name'] ?? ''), $u->error),
            'can_retry' => $u->status === 'failed' || $stuck,
            'finished_at' => $u->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Terminó (bien o mal). Si quedó lista: el batch pasa a "En test", lo nuevo se trae de Meta y, si quedaron prendidas todas
     * las de la misión, la misión se marca sola. En los dos casos, el dueño se entera en su campanita.
     */
    public static function finished(MetaUpload $u): void
    {
        $plan = $u->plan ?? [];
        $code = $plan['batch']['code'] ?? 'El batch';
        try {
            if ($u->status === 'done') {
                $batch = CreativeBatch::find($u->creative_batch_id);
                if ($batch && in_array($batch->status, QueueRules::BANK, true)) {
                    $batch->forceFill(['status' => 'testing', 'testing_at' => now(), 'status_at' => now(), 'status_by' => null])->save();
                }
                self::markMission($u);
                // Con el Meta simulado no se creó nada: no se le pregunta nada al de verdad
                if ($u->account?->meta_connection_id && !MetaUploadGateway::simulated()) {
                    SyncMetaConnection::dispatch($u->account->meta_connection_id, 'scheduled', null, ['account' => $u->meta_ad_account_id]);
                }
            }
            $message = $u->status === 'done'
                ? ($u->paused ? "{$code} quedó subido EN PAUSA en {$plan['campaign']['name']}: revísalo y préndelo en el Administrador."
                    : "{$code} quedó subido y prendido en {$plan['campaign']['name']}.")
                : "No se pudo subir {$code} a Meta: {$u->error}.";
            foreach (User::whereNull('deactivated_at')->whereHas('role', fn ($q) => $q->whereIn('description', ['Admin', 'Master']))->get() as $owner) {
                $owner->notify(new MetaWorkNotification('meta_ready', $message, '/meta-ads/analisis'));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Con todas las subidas de la misión listas y prendidas, la misión queda hecha (las en pausa las marca el dueño). */
    private static function markMission(MetaUpload $u): void
    {
        $analysis = $u->meta_analysis_id ? MetaAnalysis::find($u->meta_analysis_id) : null;
        if (!$analysis || !$u->mission_key) {
            return;
        }
        $all = MetaUpload::where('meta_analysis_id', $analysis->id)->where('mission_key', $u->mission_key)->get();
        if ($all->contains(fn (MetaUpload $x) => $x->status !== 'done' || $x->paused)) {
            return;
        }
        $targets = collect($analysis->urgent ?? [])->firstWhere('key', $u->mission_key)['upload'] ?? [];
        if (count($targets) > $all->count()) {
            return; // falta subir alguno de la misión
        }
        $marks = $analysis->marks ?? [];
        if (!isset($marks[$u->mission_key])) {
            $marks[$u->mission_key] = ['status' => 'done', 'note' => 'Subido a Meta por el sistema', 'by' => 'El sistema', 'at' => now()->toIso8601String()];
            $analysis->forceFill(['marks' => $marks])->save();
        }
    }

    /** Sin avanzar en 10 minutos (el trabajo se cortó): se puede reintentar. */
    private static function stuck(MetaUpload $u): bool
    {
        return $u->isOpen() && $u->status !== 'waiting' && $u->updated_at !== null && $u->updated_at->lt(now()->subMinutes(10));
    }

    /** @return list<array{code: string, campaign: string, min: ?float}> */
    private function targets(MetaAnalysis $analysis, string $key): array
    {
        $item = collect($analysis->urgent ?? [])->firstWhere('key', $key);
        $targets = array_values(array_filter((array) ($item['upload'] ?? []), fn ($t) => is_array($t) && !empty($t['code']) && !empty($t['campaign'])));
        if ($targets === []) {
            throw ValidationException::withMessages(['upload' => 'Esta misión no tiene batches para subir solos.']);
        }

        return $targets;
    }

    private function batch(string $code): CreativeBatch
    {
        $batch = preg_match('/^B(\d+)$/', $code, $m) ? CreativeBatch::where('number', (int) $m[1])->first() : null;
        if (!$batch) {
            throw ValidationException::withMessages(['upload' => "No encuentro el batch {$code}."]);
        }

        return $batch;
    }
}
