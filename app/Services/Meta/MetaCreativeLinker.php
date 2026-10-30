<?php

namespace App\Services\Meta;

use App\Models\AdAngle;
use App\Models\MetaAd;
use App\Models\MetaAdset;
use App\Models\MetaCreative;
use Illuminate\Support\Facades\DB;

/**
 * Enlaza cada anuncio con su creativo y arma el diccionario de ángulos (Etapa 5, paso 1b), desde la base: nunca llama a
 * Meta. Corre al final de cada sincronización de la jerarquía, al clasificar una campaña, al corregir un código a mano,
 * al cambiar un ángulo y con `php artisan meta:relink-creatives`.
 *
 * - Lee el nombre de cada ad set y de cada anuncio (CreativeTrackingId). El anuncio sin ángulo o sin batch en su nombre
 *   toma los de su ad set.
 * - El creativo es "{producto de la campaña}:{código}" (el código puesto a mano por el Admin, si lo hay, §32). Así el
 *   mismo anuncio en las 3 ciudades es un solo creativo y el C004 de un producto no se junta con el de otro.
 * - Cada código de ángulo nuevo se agrega a ad_angles. Regla de alias: si un anuncio dice un ángulo y su ad set otro que
 *   empieza igual (FAS en un ad set FASP), el más corto es lo mismo que el más largo, pero solo si el corto es nuevo: si
 *   el dueño ya lo separó o lo confirmó, no se toca.
 * - Los creativos que se quedan sin anuncios no se borran: dejan de verse.
 */
class MetaCreativeLinker
{
    /**
     * @param array{account?: int, campaign?: string, product?: int} $scope sin nada, todo
     * @return array{adsets: int, ads: int, linked: int, creatives_new: int, angles_new: int, aliases_new: int}
     */
    public function run(array $scope = []): array
    {
        $stats = ['adsets' => 0, 'ads' => 0, 'linked' => 0, 'creatives_new' => 0, 'angles_new' => 0, 'aliases_new' => 0];
        $campaignQuery = DB::table('meta_campaigns')
            ->when($scope['account'] ?? null, fn ($q, $v) => $q->where('meta_ad_account_id', $v))
            ->when($scope['campaign'] ?? null, fn ($q, $v) => $q->where('meta_id', $v))
            ->when($scope['product'] ?? null, fn ($q, $v) => $q->where('product_id', $v));
        $campaigns = $campaignQuery->get(['meta_id', 'product_id', 'name']);
        $productOf = $campaigns->mapWithKeys(fn ($c) => [$c->meta_id => $c->product_id !== null ? (int) $c->product_id : null])->all();
        // La primera palabra del nombre de la campaña también dice el producto ("SOLOBRILLO | CSS | CBO")
        $campaignWord = $campaigns->mapWithKeys(fn ($c) => [$c->meta_id => preg_split('/[^\p{L}\p{N}]+/u', trim((string) $c->name))[0] ?? ''])->all();
        if (!$productOf && (isset($scope['campaign']) || isset($scope['product']))) {
            return $stats;
        }
        $productNames = DB::table('products')->whereIn('id', array_filter(array_unique(array_values($productOf))))->get(['id', 'title', 'showable_name'])
            ->mapWithKeys(fn ($p) => [(int) $p->id => array_values(array_filter([$p->title, $p->showable_name]))])->all();
        $names = fn (string $campaignMetaId) => array_values(array_filter([...($productNames[$productOf[$campaignMetaId] ?? 0] ?? []),
            mb_strlen($campaignWord[$campaignMetaId] ?? '') >= 4 ? $campaignWord[$campaignMetaId] : null]));
        $scoped = function ($q) use ($scope, $productOf) {
            if (isset($scope['account'])) {
                $q->where('meta_ad_account_id', $scope['account']);
            }
            if (isset($scope['campaign']) || isset($scope['product'])) {
                $q->whereIn('campaign_meta_id', array_map('strval', array_keys($productOf)));
            }

            return $q;
        };

        // 1. Lo que dice cada ad set y cada anuncio
        $adsets = $scoped(MetaAdset::query())->get(['id', 'meta_id', 'campaign_meta_id', 'name', 'batch_code', 'angle_code', 'concept', 'stage']);
        $adsetParts = [];
        foreach ($adsets as $s) {
            $adsetParts[$s->meta_id] = CreativeTrackingId::parseAdset($s->name, $names((string) $s->campaign_meta_id));
        }
        $ads = $scoped(MetaAd::query())->get(['id', 'meta_id', 'campaign_meta_id', 'adset_meta_id', 'name', 'creative_type',
            'creative_tracking_id', 'tracking_id_source', 'meta_creative_ref_id', 'creative_code', 'angle_code', 'batch_code',
            'ref_code', 'iteration_of']);
        $adParts = [];
        $codes = [];
        $pairs = [];
        foreach ($adsets as $s) {
            $p = $productOf[$s->campaign_meta_id] ?? null;
            if ($p !== null && $adsetParts[$s->meta_id]['angle'] !== null) {
                $codes[$p][$adsetParts[$s->meta_id]['angle']] = true;
            }
        }
        foreach ($ads as $ad) {
            $p = $productOf[$ad->campaign_meta_id] ?? null;
            $parts = CreativeTrackingId::parse($ad->name, $names((string) $ad->campaign_meta_id));
            $setAngle = $adsetParts[$ad->adset_meta_id]['angle'] ?? null;
            $adParts[$ad->id] = $parts;
            if ($p === null) {
                continue;
            }
            if ($parts['angle'] !== null) {
                $codes[$p][$parts['angle']] = true;
                if ($setAngle !== null && $setAngle !== $parts['angle']) {
                    $pairs[$p][] = [$parts['angle'], $setAngle];
                }
            }
        }

        // 2. El diccionario de ángulos de cada producto
        $resolve = [];
        foreach ($codes as $productId => $found) {
            $resolve[$productId] = $this->angles($productId, array_keys($found), $pairs[$productId] ?? [], $stats);
        }
        $angle = fn (?int $p, ?string $code) => $code === null ? null : ($p !== null ? ($resolve[$p][$code] ?? $code) : $code);

        foreach ($adsets as $s) {
            $parts = $adsetParts[$s->meta_id];
            $s->fill(['batch_code' => $parts['batch'], 'angle_code' => $angle($productOf[$s->campaign_meta_id] ?? null, $parts['angle']),
                'concept' => $parts['concept'], 'stage' => $parts['stage']]);
            if ($s->isDirty()) {
                $s->save();
                $stats['adsets']++;
            }
        }

        // 3. Cada anuncio con su creativo
        $creativeIds = MetaCreative::pluck('id', 'tracking_id')->all();
        $touched = [];
        foreach ($ads as $ad) {
            $p = $productOf[$ad->campaign_meta_id] ?? null;
            $parts = $adParts[$ad->id];
            $set = $adsetParts[$ad->adset_meta_id] ?? ['angle' => null, 'batch' => null];
            $ad->fill([
                'creative_code' => $parts['creative'],
                'angle_code' => $angle($p, $parts['angle'] ?? $set['angle']),
                'batch_code' => $parts['batch'] ?? $set['batch'],
                'ref_code' => $parts['ref'],
                'iteration_of' => $parts['iteration_of'],
            ]);
            if ($ad->tracking_id_source !== 'manual') {
                $ad->creative_tracking_id = $parts['code'];
                $ad->tracking_id_source = $parts['code'] !== null ? 'auto' : null;
            }
            $creativeId = null;
            if ($ad->creative_tracking_id) {
                $key = self::creativeKey($p, $ad->creative_tracking_id);
                if (!isset($creativeIds[$key])) {
                    $creativeIds[$key] = MetaCreative::create(['tracking_id' => $key, 'product_id' => $p, 'type' => $ad->creative_type])->id;
                    $stats['creatives_new']++;
                }
                $creativeId = $creativeIds[$key];
                $touched[$creativeId] = true;
            }
            if ($ad->meta_creative_ref_id !== null && $ad->meta_creative_ref_id !== $creativeId) {
                $touched[$ad->meta_creative_ref_id] = true; // el creativo que deja: se recalcula su tipo y su nombre
            }
            $ad->meta_creative_ref_id = $creativeId;
            if ($ad->isDirty()) {
                $ad->save();
                $stats['ads']++;
            }
            $stats['linked'] += $creativeId !== null ? 1 : 0;
        }

        // 4. Lo que se muestra de cada creativo, con todos sus anuncios (de cualquier cuenta)
        $this->refreshCreatives(array_keys($touched));

        return $stats;
    }

    /** La identidad de un creativo: el producto de la campaña (0 si falta clasificarla) y el código. */
    public static function creativeKey(?int $productId, string $code): string
    {
        return ($productId ?? 0) . ':' . $code;
    }

    /**
     * La regla de alias (PHP puro): de los pares (ángulo del anuncio, ángulo de su ad set) en los que uno empieza con el
     * otro, el más corto es lo mismo que el más largo, si el corto es nuevo. Las cadenas (FA → FAS → FASP) terminan en el
     * más largo.
     *
     * @param array<int, array{0: string, 1: string}> $pairs
     * @param string[] $known los códigos que ya están en el diccionario
     * @return array<string, string> corto => largo
     */
    public static function aliases(array $pairs, array $known): array
    {
        $map = [];
        foreach ($pairs as [$a, $b]) {
            if ($a === $b) {
                continue;
            }
            [$short, $long] = strlen($a) < strlen($b) ? [$a, $b] : [$b, $a];
            if (!str_starts_with($long, $short) || in_array($short, $known, true) || isset($map[$short])) {
                continue;
            }
            $map[$short] = $long;
        }
        foreach (array_keys($map) as $short) {
            $seen = [$short => true];
            while (isset($map[$map[$short]]) && !isset($seen[$map[$short]])) {
                $seen[$map[$short]] = true;
                $map[$short] = $map[$map[$short]];
            }
        }

        return $map;
    }

    /**
     * Agrega los códigos nuevos de un producto al diccionario y devuelve cómo se lee cada código (el alias, como el
     * código al que apunta).
     *
     * @return array<string, string>
     */
    private function angles(int $productId, array $found, array $pairs, array &$stats): array
    {
        $rows = AdAngle::where('product_id', $productId)->get()->keyBy('code');
        $aliases = self::aliases($pairs, $rows->keys()->all());
        foreach ($found as $code) {
            if (!isset($rows[$code]) && !isset($aliases[$code])) {
                $rows[$code] = AdAngle::create(['product_id' => $productId, 'code' => $code]);
                $stats['angles_new']++;
            }
        }
        foreach ($aliases as $short => $long) {
            if (isset($rows[$short])) {
                continue;
            }
            if (!isset($rows[$long])) {
                $rows[$long] = AdAngle::create(['product_id' => $productId, 'code' => $long]);
                $stats['angles_new']++;
            }
            $target = $rows[$long];
            $rows[$short] = AdAngle::create(['product_id' => $productId, 'code' => $short, 'alias_of' => $target->alias_of ?? $target->id]);
            $stats['angles_new']++;
            $stats['aliases_new']++;
        }

        return self::resolveMap($rows->values()->all());
    }

    /**
     * Cada código del diccionario de un producto con el código al que se lee (PHP puro).
     *
     * @param AdAngle[]|object[] $rows con id, code y alias_of
     * @return array<string, string>
     */
    public static function resolveMap(array $rows): array
    {
        $byId = [];
        foreach ($rows as $r) {
            $byId[$r->id] = $r;
        }
        $map = [];
        foreach ($rows as $r) {
            $cur = $r;
            for ($hops = 0; $cur->alias_of !== null && isset($byId[$cur->alias_of]) && $hops < 5; $hops++) {
                $cur = $byId[$cur->alias_of];
            }
            $map[$r->code] = $cur->code;
        }

        return $map;
    }

    /** El nombre, el tipo, el producto, el ángulo, el batch y el padre de cada creativo, por su anuncio más nuevo. */
    private function refreshCreatives(array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            $ads = DB::table('meta_ads')->whereIn('meta_creative_ref_id', $chunk)
                ->orderByDesc('created_time')->orderByDesc('id')
                ->get(['meta_creative_ref_id', 'name', 'creative_type', 'creative_tracking_id', 'tracking_id_source', 'creative_code',
                    'angle_code', 'batch_code', 'iteration_of'])
                ->groupBy('meta_creative_ref_id');
            foreach (MetaCreative::whereIn('id', $chunk)->get() as $creative) {
                $list = $ads->get($creative->id);
                if (!$list) {
                    continue; // se quedó sin anuncios: no se borra, deja de verse
                }
                $newest = $list->first();
                $types = $list->pluck('creative_type')->filter()->unique();
                $prefix = strstr((string) $creative->tracking_id, ':', true);
                $creative->fill([
                    'product_id' => $prefix !== false && (int) $prefix > 0 ? (int) $prefix : $creative->product_id,
                    'label' => mb_substr($newest->tracking_id_source === 'manual'
                        ? (string) $newest->creative_tracking_id : CreativeTrackingId::label($newest->name), 0, 255),
                    'creative_code' => $newest->creative_code,
                    'angle_code' => $newest->angle_code,
                    'batch_code' => $newest->batch_code,
                    'iteration_of' => $newest->iteration_of,
                    'type' => $types->count() > 1 ? 'mixed' : ($types->first() ?? $creative->type),
                ]);
                if ($creative->isDirty()) {
                    $creative->save();
                }
            }
        }
    }
}
