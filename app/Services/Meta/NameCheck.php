<?php

namespace App\Services\Meta;

use App\Models\CreativeBatch;
use App\Services\Meta\Queue\QueueRules;
use Illuminate\Support\Facades\DB;

/**
 * `.89` (dueño, 2026-11-03: "es importante que el sistema siempre pueda saber de qué creativo, de qué batch y de qué
 * campaña estamos hablando"; eligió que avise "solo lo que no puede reconocer"): en las campañas activas, lo que rompe la
 * cadena: la campaña sin producto o ciudad (no se clasificó sola), sus ad sets prendidos sin número B y sus anuncios
 * prendidos sin código C. Los nombres viejos que el sistema sí entiende ("CRE001-FLEX-FAS-BATCH004") no se avisan. Solo lee.
 */
class NameCheck
{
    /** @return list<array{campaign_meta_id: string, campaign: string, product_id: ?int, product: ?string, unclassified: bool, sets: list<string>, ads: list<string>}> */
    public static function find(): array
    {
        $campaigns = DB::table('meta_campaigns as c')->join('meta_ad_accounts as a', 'a.id', '=', 'c.meta_ad_account_id')
            ->leftJoin('products as p', 'p.id', '=', 'c.product_id')
            ->where('a.is_active', true)->where('c.effective_status', 'ACTIVE')->whereNull('c.missing_since')
            ->get(['c.meta_id', 'c.name', 'c.product_id', 'c.city_id', 'p.title', 'p.showable_name']);
        if ($campaigns->isEmpty()) {
            return [];
        }
        $ids = $campaigns->pluck('meta_id')->map(fn ($v) => (string) $v)->all();
        $sets = DB::table('meta_adsets')->whereIn('campaign_meta_id', $ids)->where('effective_status', 'ACTIVE')->whereNull('missing_since')
            ->whereNull('batch_code')->orderBy('name')->get(['campaign_meta_id', 'name'])->groupBy('campaign_meta_id');
        $ads = DB::table('meta_ads')->whereIn('campaign_meta_id', $ids)->where('effective_status', 'ACTIVE')->whereNull('missing_since')
            ->whereNull('creative_code')->orderBy('name')->get(['campaign_meta_id', 'name'])->groupBy('campaign_meta_id');
        $out = [];
        foreach ($campaigns as $c) {
            $id = (string) $c->meta_id;
            $unclassified = $c->product_id === null || $c->city_id === null;
            $badSets = ($sets[$id] ?? collect())->pluck('name')->map(fn ($v) => (string) $v)->all();
            $badAds = ($ads[$id] ?? collect())->pluck('name')->map(fn ($v) => (string) $v)->all();
            if ($unclassified || $badSets || $badAds) {
                $out[] = ['campaign_meta_id' => $id, 'campaign' => (string) $c->name, 'product_id' => $c->product_id !== null ? (int) $c->product_id : null,
                    'product' => $c->showable_name ?: $c->title, 'unclassified' => $unclassified, 'sets' => $badSets, 'ads' => $badAds];
            }
        }

        return $out;
    }

    /**
     * `.163` (dueño, 2026-11-19: LegUp en Caracas tenía dos anuncios prendidos "C0181 CERV"; el del ad set B0128 ROD era en
     * realidad C0186 y el sistema sumaba sus resultados al C0181; eligió que salga una misión "Corrige el nombre"): por
     * campaña activa, los anuncios prendidos cuyo código C es una pieza de otro batch (no del de su ad set) y los códigos C
     * que se repiten en ad sets de batches distintos de la misma campaña. Solo los nombres nuevos (C#### y B####). Solo lee.
     *
     * @return list<array{campaign_meta_id: string, campaign: string, product_id: ?int, product: ?string, city: ?string, ads: list<array>, repeats: list<array>}>
     */
    public static function mismatches(): array
    {
        $campaigns = DB::table('meta_campaigns as c')->join('meta_ad_accounts as a', 'a.id', '=', 'c.meta_ad_account_id')
            ->leftJoin('products as p', 'p.id', '=', 'c.product_id')->leftJoin('cities as ci', 'ci.id', '=', 'c.city_id')
            ->where('a.is_active', true)->where('c.effective_status', 'ACTIVE')->whereNull('c.missing_since')
            ->get(['c.meta_id', 'c.name', 'c.product_id', 'ci.name as city', 'p.title', 'p.showable_name'])->keyBy(fn ($c) => (string) $c->meta_id);
        if ($campaigns->isEmpty()) {
            return [];
        }
        $ids = $campaigns->keys()->all();
        $sets = DB::table('meta_adsets')->whereIn('campaign_meta_id', $ids)->whereNull('missing_since')->get(['meta_id', 'campaign_meta_id', 'name', 'batch_code'])
            ->mapWithKeys(fn ($s) => [(string) $s->meta_id => ['name' => (string) $s->name, 'batch_code' => $s->batch_code, 'campaign_meta_id' => (string) $s->campaign_meta_id]])->all();
        $ads = DB::table('meta_ads')->whereIn('campaign_meta_id', $ids)->whereNull('missing_since')
            ->get(['meta_id', 'name', 'campaign_meta_id', 'adset_meta_id', 'creative_code', 'effective_status'])
            ->map(fn ($a) => ['meta_id' => (string) $a->meta_id, 'name' => (string) $a->name, 'campaign_meta_id' => (string) $a->campaign_meta_id,
                'adset_meta_id' => (string) $a->adset_meta_id, 'creative_code' => $a->creative_code, 'active' => $a->effective_status === 'ACTIVE'])->all();
        // Los batches que se nombran: los de los ad sets y los de las piezas de los anuncios
        $pieceNumbers = array_values(array_filter(array_map(fn ($a) => self::number($a['creative_code'], 'C'), $ads)));
        $batchNumbers = array_values(array_filter(array_map(fn ($s) => self::number($s['batch_code'], 'B'), $sets)));
        $owners = DB::table('creative_batch_pieces')->whereIn('number', $pieceNumbers ?: [0])->pluck('creative_batch_id')->all();
        $pieces = [];
        $batches = [];
        foreach (CreativeBatch::with('pieces')->whereNotNull('number')->where(fn ($q) => $q->whereIn('number', $batchNumbers ?: [0])->orWhereIn('id', $owners ?: [0]))->get() as $b) {
            $batches[(int) $b->number] = ['adset' => Naming::batch((int) $b->number, $b->angle_code, [])['adset'], 'pieces' => []];
            foreach ($b->pieces->sortBy('position')->filter(fn ($p) => $p->number !== null) as $p) {
                $pieces[(int) $p->number] = (int) $b->number;
                $batches[(int) $b->number]['pieces'][(int) $p->number] = Naming::batch(null, $b->angle_code, [(int) $p->number])['ads'][0];
            }
        }
        $out = [];
        foreach (self::wrong($ads, $sets, $pieces, $batches) as $id => $w) {
            $c = $campaigns[$id] ?? null;
            if ($c === null) {
                continue;
            }
            $out[] = ['campaign_meta_id' => (string) $id, 'campaign' => (string) $c->name, 'product_id' => $c->product_id !== null ? (int) $c->product_id : null,
                'product' => $c->showable_name ?: $c->title, 'city' => $c->city, 'ads' => $w['ads'] ?? [], 'repeats' => $w['repeats'] ?? []];
        }

        return $out;
    }

    /**
     * `.163`: lo de `mismatches`, sin base de datos. Un anuncio prendido no cuadra si su código C es una pieza de un batch
     * distinto del de su ad set; si a ese ad set le falta una sola de sus piezas y es el único que no cuadra ahí, esa es la que
     * seguramente es (`suggest`). Un código C que no es de ninguna pieza del sistema y está prendido en ad sets de batches
     * distintos de la misma campaña, se repite. PHP puro.
     *
     * @param list<array{meta_id: string, name: string, campaign_meta_id: string, adset_meta_id: string, creative_code: ?string, active: bool}> $ads
     * @param array<string, array{name: string, batch_code: ?string, campaign_meta_id: string}> $sets
     * @param array<int, int> $pieces número de pieza => número de su batch
     * @param array<int, array{adset: ?string, pieces: array<int, string>}> $batches número de batch => su nombre en Meta y el de cada pieza
     * @return array<string, array{ads?: list<array{meta_id: string, name: string, adset: string, code: string, belongs: string, suggest: ?string, options: list<string>}>, repeats?: list<array{name: string, adsets: list<string>}>}>
     */
    public static function wrong(array $ads, array $sets, array $pieces, array $batches): array
    {
        $out = [];
        $bySet = [];
        foreach ($ads as $a) {
            $c = self::number($a['creative_code'], 'C');
            $b = self::number($sets[$a['adset_meta_id']]['batch_code'] ?? null, 'B');
            if ($a['active'] && $c !== null && $b !== null && isset($pieces[$c]) && $pieces[$c] !== $b) {
                $bySet[$a['adset_meta_id']][] = $a;
            }
        }
        $flagged = [];
        foreach ($bySet as $setId => $list) {
            $set = $sets[$setId];
            $b = self::number($set['batch_code'], 'B');
            // Las piezas de su batch que ningún anuncio de este ad set lleva (prendido o no)
            $here = array_filter(array_map(fn ($a) => $a['adset_meta_id'] === $setId ? self::number($a['creative_code'], 'C') : null, $ads));
            $missing = array_values(array_diff_key($batches[$b]['pieces'] ?? [], array_flip($here)));
            foreach ($list as $a) {
                $owner = $pieces[self::number($a['creative_code'], 'C')];
                $flagged[] = $a['meta_id'];
                $out[$set['campaign_meta_id']]['ads'][] = ['meta_id' => $a['meta_id'], 'name' => trim($a['name']), 'adset' => trim($set['name']), 'code' => (string) $a['creative_code'],
                    'belongs' => $batches[$owner]['adset'] ?? QueueRules::batchCode($owner),
                    'suggest' => count($list) === 1 && count($missing) === 1 ? $missing[0] : null, 'options' => $missing];
            }
        }
        $groups = [];
        foreach ($ads as $a) {
            $c = self::number($a['creative_code'], 'C');
            if ($a['active'] && $c !== null && !isset($pieces[$c]) && !in_array($a['meta_id'], $flagged, true)) {
                $groups[$a['campaign_meta_id']][$c][] = $a;
            }
        }
        foreach ($groups as $campaignId => $codes) {
            foreach ($codes as $list) {
                $setBatches = array_unique(array_map(fn ($a) => (string) ($sets[$a['adset_meta_id']]['batch_code'] ?? $a['adset_meta_id']), $list));
                if (count($setBatches) > 1) {
                    $out[$campaignId]['repeats'][] = ['name' => trim($list[0]['name']),
                        'adsets' => array_values(array_unique(array_map(fn ($a) => trim((string) ($sets[$a['adset_meta_id']]['name'] ?? '')), $list)))];
                }
            }
        }

        return $out;
    }

    /** `.163`: el número de un código nuevo ("C0181" → 181, "B0128" → 128); los viejos ("C005", "B004", "CRE001") no cuentan. */
    private static function number(?string $code, string $letter): ?int
    {
        return $code !== null && preg_match('/^' . $letter . '(\d{4,})$/', $code, $m) ? (int) $m[1] : null;
    }
}
