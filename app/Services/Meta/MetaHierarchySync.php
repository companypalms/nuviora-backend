<?php

namespace App\Services\Meta;

use App\Models\MetaAd;
use App\Models\MetaAdAccount;
use App\Models\MetaAdset;
use App\Models\MetaCampaign;
use App\Services\Meta\Landings\LandingTestTracker;
use App\Services\Meta\Landings\LandingTracker;
use App\Services\Meta\Queue\BatchTracker;
use Illuminate\Support\Arr;

/**
 * Campañas, ad sets y anuncios de una cuenta (documento de Fran del 2026-10-08, Módulo 2):
 * - se identifican por el ID de Meta; un cambio de nombre actualiza el mismo registro (§9, §10);
 * - lo que Meta deja de devolver no se borra: queda con su último estado conocido y la fecha (§42, §43);
 * - cada anuncio guarda su creativo de Meta y el creative_tracking_id detectado, salvo que el Admin lo haya puesto a
 *   mano (§32): lo hace MetaCreativeLinker.
 * Etapa 5, paso 1a (2026-10-30): además, el texto, el título, la imagen, los parámetros de URL y la landing de cada
 * anuncio, la duración de sus videos, los mínimos y topes de gasto de los ad sets de una CBO, y una foto de los
 * presupuestos y estados cada vez que cambian (MetaSnapshots). Si Meta no acepta un campo nuevo, se piden los de siempre.
 * Paso 1b: al final, MetaCreativeLinker lee los nombres, enlaza cada anuncio con su creativo (producto + código) y suma los
 * ángulos nuevos al diccionario.
 * Paso 6a: LandingTracker anota a qué landing lleva cada anuncio y desde cuándo.
 * Una llamada por nivel y cuenta, con paginación (§52: no una llamada por anuncio).
 */
class MetaHierarchySync
{
    /** Todos los estados menos DELETED, para que también lleguen los pausados y archivados (§42). */
    public const STATUSES = ['ACTIVE', 'PAUSED', 'ARCHIVED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED', 'IN_PROCESS', 'WITH_ISSUES',
        'PENDING_REVIEW', 'DISAPPROVED', 'PREAPPROVED', 'PENDING_BILLING_INFO'];

    private const CAMPAIGN_FIELDS = 'id,name,status,effective_status,objective,created_time,updated_time,start_time,stop_time,buying_type,daily_budget,lifetime_budget,bid_strategy';
    private const ADSET_FIELDS = 'id,name,campaign_id,status,effective_status,optimization_goal,billing_event,attribution_spec,promoted_object,created_time,updated_time,daily_budget,lifetime_budget';
    /** Paso 1a: los mínimos y topes de gasto de un ad set dentro de una CBO. */
    private const ADSET_EXTRA = ',daily_min_spend_target,daily_spend_cap';
    private const AD_FIELDS = 'id,name,adset_id,campaign_id,status,effective_status,created_time,updated_time,creative{id,name,object_type,video_id,image_hash,thumbnail_url}';
    /** Paso 1a: lo que dice el anuncio y a dónde lleva. */
    private const AD_FIELDS_FULL = 'id,name,adset_id,campaign_id,status,effective_status,created_time,updated_time,creative{id,name,object_type,video_id,image_hash,thumbnail_url,body,title,image_url,url_tags,object_story_spec,asset_feed_spec}';

    /** Si la última lista se pidió con los campos nuevos (si no, no se pisan los datos guardados). */
    private bool $full = true;

    public function __construct(
        private MetaSnapshots $snapshots = new MetaSnapshots(),
        private MetaCreativeLinker $linker = new MetaCreativeLinker(),
    ) {
    }

    /** @return int registros procesados */
    public function sync(MetaAdAccount $account, MetaClient $client): int
    {
        $act = $account->actId();
        $now = now();

        $campaigns = $this->fetch($client, "{$act}/campaigns", self::CAMPAIGN_FIELDS);
        $campaignIds = [];
        $states = ['campaign' => [], 'adset' => [], 'ad' => []];
        foreach ($campaigns as $row) {
            $c = MetaCampaign::updateOrCreate(['meta_id' => (string) $row['id']], [
                'meta_ad_account_id' => $account->id,
                'name' => $row['name'] ?? null,
                'status' => $row['status'] ?? null,
                'effective_status' => $row['effective_status'] ?? null,
                'objective' => $row['objective'] ?? null,
                'created_time' => $this->time($row['created_time'] ?? null),
                'start_time' => $this->time($row['start_time'] ?? null),
                'stop_time' => $this->time($row['stop_time'] ?? null),
                'last_known_state' => $row['effective_status'] ?? null,
                'last_seen_at' => $now,
                'missing_since' => null,
                'meta' => Arr::except($row, ['id', 'name', 'status', 'effective_status', 'objective', 'created_time', 'start_time', 'stop_time']),
            ]);
            $campaignIds[$c->meta_id] = $c->id;
            $states['campaign'][$c->meta_id] = MetaSnapshots::state('campaign', $row);
        }
        $this->markMissing(MetaCampaign::class, $account, array_keys($campaignIds), $now);
        $campaignIds += MetaCampaign::where('meta_ad_account_id', $account->id)->pluck('id', 'meta_id')->all();

        $adsets = $this->fetch($client, "{$act}/adsets", self::ADSET_FIELDS . self::ADSET_EXTRA, self::ADSET_FIELDS);
        $adsetIds = [];
        foreach ($adsets as $row) {
            $campaignMetaId = (string) ($row['campaign_id'] ?? '');
            $s = MetaAdset::updateOrCreate(['meta_id' => (string) $row['id']], [
                'meta_ad_account_id' => $account->id,
                'meta_campaign_id' => $campaignIds[$campaignMetaId] ?? null,
                'campaign_meta_id' => $campaignMetaId,
                'name' => $row['name'] ?? null,
                'status' => $row['status'] ?? null,
                'effective_status' => $row['effective_status'] ?? null,
                'optimization_goal' => $row['optimization_goal'] ?? null,
                'attribution_spec' => $row['attribution_spec'] ?? null,
                'created_time' => $this->time($row['created_time'] ?? null),
                'last_known_state' => $row['effective_status'] ?? null,
                'last_seen_at' => $now,
                'missing_since' => null,
                'meta' => Arr::except($row, ['id', 'name', 'campaign_id', 'status', 'effective_status', 'optimization_goal', 'attribution_spec', 'created_time']),
            ]);
            $adsetIds[$s->meta_id] = $s->id;
            $states['adset'][$s->meta_id] = MetaSnapshots::state('adset', $row);
        }
        $this->markMissing(MetaAdset::class, $account, array_keys($adsetIds), $now);
        $adsetIds += MetaAdset::where('meta_ad_account_id', $account->id)->pluck('id', 'meta_id')->all();

        $ads = $this->fetch($client, "{$act}/ads", self::AD_FIELDS_FULL, self::AD_FIELDS);
        $seenAds = [];
        foreach ($ads as $row) {
            $creative = $row['creative'] ?? [];
            $ad = MetaAd::firstOrNew(['meta_id' => (string) $row['id']]);
            // Lo que dice el anuncio y a dónde lleva va en sus columnas; en "meta" no se repite
            $meta = Arr::except($row, ['id', 'name', 'adset_id', 'campaign_id', 'status', 'effective_status', 'created_time']);
            unset($meta['creative']['object_story_spec'], $meta['creative']['asset_feed_spec'], $meta['creative']['body']);
            $ad->fill([
                'meta_ad_account_id' => $account->id,
                'meta_campaign_id' => $campaignIds[(string) ($row['campaign_id'] ?? '')] ?? null,
                'meta_adset_id' => $adsetIds[(string) ($row['adset_id'] ?? '')] ?? null,
                'campaign_meta_id' => (string) ($row['campaign_id'] ?? ''),
                'adset_meta_id' => (string) ($row['adset_id'] ?? ''),
                'name' => $row['name'] ?? null,
                'status' => $row['status'] ?? null,
                'effective_status' => $row['effective_status'] ?? null,
                'created_time' => $this->time($row['created_time'] ?? null),
                'meta_creative_id' => isset($creative['id']) ? (string) $creative['id'] : null,
                'creative_type' => self::creativeType($creative),
                'video_id' => isset($creative['video_id']) ? (string) $creative['video_id'] : null,
                'image_hash' => $creative['image_hash'] ?? null,
                'thumbnail_url' => $creative['thumbnail_url'] ?? null,
                'last_known_state' => $row['effective_status'] ?? null,
                'last_seen_at' => $now,
                'missing_since' => null,
                'meta' => $meta,
            ]);
            if ($this->full) {
                $ad->fill(self::creativeDetails($creative));
            }
            $ad->save();
            $seenAds[] = $ad->meta_id;
            $states['ad'][$ad->meta_id] = MetaSnapshots::state('ad', $row);
        }
        $this->markMissing(MetaAd::class, $account, $seenAds, $now);
        $this->linker->run(['account' => $account->id]);
        // Paso 6a: a qué landing lleva cada anuncio y desde cuándo (un error aquí no frena la sincronización)
        try {
            (new LandingTracker())->run($account->id, $now);
            // Paso 6b: los tests de las landings publicadas (con los mismos anuncios antes y después)
            (new LandingTestTracker())->run($now);
        } catch (\Throwable $e) {
            report($e);
        }
        // Paso 4a: los encargos de la cola que ya aparecen en Meta pasan a "En test" (un error aquí no frena la sincronización)
        try {
            (new BatchTracker())->run(null, false);
        } catch (\Throwable $e) {
            report($e);
        }
        $this->videoLengths($account, $client);

        foreach ($states as $level => $byId) {
            $this->snapshots->record($account, $level, $byId, $now);
        }

        return count($campaigns) + count($adsets) + count($ads);
    }

    /**
     * El texto, el título, la imagen, los parámetros de URL (los UTM) y la landing de un creativo (PHP puro). Meta los
     * pone en distintos lugares según el tipo de anuncio: el creativo, su object_story_spec (link_data o video_data) o
     * su asset_feed_spec (los anuncios dinámicos).
     *
     * @return array{ad_body: ?string, ad_title: ?string, image_url: ?string, url_tags: ?string, landing_url: ?string}
     */
    public static function creativeDetails(array $creative): array
    {
        $story = (array) ($creative['object_story_spec'] ?? []);
        $link = (array) ($story['link_data'] ?? []);
        $video = (array) ($story['video_data'] ?? []);
        $feed = (array) ($creative['asset_feed_spec'] ?? []);
        $first = function ($list, string $key): ?string {
            foreach (is_array($list) ? $list : [] as $item) {
                if (is_array($item) && !empty($item[$key]) && is_string($item[$key])) {
                    return $item[$key];
                }
            }
            return null;
        };
        $text = fn (...$values) => collect($values)->first(fn ($v) => is_string($v) && trim($v) !== '');

        $title = $text($creative['title'] ?? null, $link['name'] ?? null, $video['title'] ?? null, $first($feed['titles'] ?? null, 'text'));

        return [
            'ad_body' => $text($creative['body'] ?? null, $link['message'] ?? null, $video['message'] ?? null, $first($feed['bodies'] ?? null, 'text')),
            'ad_title' => $title !== null ? mb_substr($title, 0, 500) : null,
            'image_url' => $text($creative['image_url'] ?? null, $link['picture'] ?? null, $video['image_url'] ?? null),
            'url_tags' => $text($creative['url_tags'] ?? null),
            'landing_url' => $text($link['link'] ?? null, $link['call_to_action']['value']['link'] ?? null,
                $video['call_to_action']['value']['link'] ?? null, $first($feed['link_urls'] ?? null, 'website_url'),
                $story['template_data']['link'] ?? null),
        ];
    }

    /** Video o imagen, según lo que diga Meta del creativo (§20, §23). */
    public static function creativeType(array $creative): ?string
    {
        if (!empty($creative['video_id']) || strtoupper((string) ($creative['object_type'] ?? '')) === 'VIDEO') {
            return 'video';
        }
        if (!empty($creative['image_hash']) || in_array(strtoupper((string) ($creative['object_type'] ?? '')), ['PHOTO', 'SHARE', 'IMAGE'], true)) {
            return 'image';
        }
        return null;
    }

    /**
     * La duración de los videos que todavía no la tienen: primero la lista de videos de la cuenta y, los que no estén
     * ahí (videos de la página), de a 50 por su ID. Sin permiso o sin datos queda para la próxima vuelta; nunca frena la
     * sincronización, salvo un problema de la conexión o del límite de Meta.
     */
    private function videoLengths(MetaAdAccount $account, MetaClient $client): void
    {
        $missing = MetaAd::where('meta_ad_account_id', $account->id)->whereNotNull('video_id')->whereNull('video_length')
            ->distinct()->pluck('video_id')->map(fn ($v) => (string) $v)->all();
        if (!$missing) {
            return;
        }
        $lengths = [];
        $keep = function (array $video) use (&$lengths): void {
            if (isset($video['id'], $video['length']) && is_numeric($video['length']) && (float) $video['length'] > 0) {
                $lengths[(string) $video['id']] = round((float) $video['length'], 2);
            }
        };
        $tolerate = function (callable $call): void {
            try {
                $call();
            } catch (MetaApiException $e) {
                if ($e->affectsConnection() || $e->kind === MetaApiException::RATE_LIMIT) {
                    throw $e;
                }
            }
        };
        $tolerate(function () use ($client, $account, $keep) {
            foreach ($client->getAll($account->actId() . '/advideos', ['fields' => 'id,length', 'limit' => 200], 50) as $video) {
                $keep($video);
            }
        });
        foreach (array_chunk(array_values(array_diff($missing, array_keys($lengths))), 50) as $ids) {
            $tolerate(function () use ($client, $ids, $keep) {
                foreach ($client->get('', ['ids' => implode(',', $ids), 'fields' => 'id,length']) as $video) {
                    if (is_array($video)) {
                        $keep($video);
                    }
                }
            });
        }
        foreach (array_intersect_key($lengths, array_flip($missing)) as $videoId => $length) {
            MetaAd::where('meta_ad_account_id', $account->id)->where('video_id', (string) $videoId)->update(['video_length' => $length]);
        }
    }

    /** Pide una lista; si Meta no acepta un campo nuevo (paso 1a), se piden los de siempre ($basic). */
    private function fetch(MetaClient $client, string $path, string $fields, ?string $basic = null): array
    {
        $this->full = true;
        try {
            return $this->fetchWithStatuses($client, $path, $fields);
        } catch (MetaApiException $e) {
            if ($basic === null || !in_array($e->kind, [MetaApiException::FIELD, MetaApiException::PERMISSION], true)) {
                throw $e;
            }
            $this->full = false;
            return $this->fetchWithStatuses($client, $path, $basic);
        }
    }

    private function fetchWithStatuses(MetaClient $client, string $path, string $fields): array
    {
        try {
            return $client->getAll($path, ['fields' => $fields, 'limit' => 200, 'effective_status' => self::STATUSES]);
        } catch (MetaApiException $e) {
            if ($e->kind !== MetaApiException::FIELD && $e->kind !== MetaApiException::META) {
                throw $e;
            }
            // Si Meta no acepta el filtro de estados, se pide sin él (solo llegan los no archivados).
            return $client->getAll($path, ['fields' => $fields, 'limit' => 200]);
        }
    }

    /** §43: lo que no volvió conserva sus datos; solo se anota desde cuándo falta. */
    private function markMissing(string $model, MetaAdAccount $account, array $seen, $now): void
    {
        $model::where('meta_ad_account_id', $account->id)
            ->whereNull('missing_since')
            ->when($seen, fn ($q) => $q->whereNotIn('meta_id', $seen))
            ->update(['missing_since' => $now]);
    }

    /**
     * Una fecha de Meta en la hora de la app. Una campaña sin fecha de inicio o de fin llega como el segundo -1 de la
     * época en la zona de la cuenta ("1969-12-31T19:59:59-0400"), que las columnas timestamp no admiten: una fecha
     * vacía o anterior a 1971 se guarda vacía (2026-10-17: frenaba toda la sincronización de la cuenta).
     */
    private function time(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        $date = \Illuminate\Support\Carbon::parse($value);
        return $date->copy()->utc()->year < 1971 ? null : $date->setTimezone(config('app.timezone'))->toDateTimeString();
    }
}
