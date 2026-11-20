<?php

namespace App\Services\Meta\Decisions\Rules;

/**
 * `.166` (dueño, 2026-11-20: "los creativos que fueron buenos ganadores, cuando se empeoran los apagamos, y en dos semanas les
 * hacemos una copia y los volvemos a activar a ver si vuelven a ser buenos"): revivir un ganador que descansa. PHP puro.
 *
 * - Revivido: un anuncio de un creativo en una campaña, creado 14 días (o más) después del último gasto del creativo ahí.
 * - Se juzga como un creativo nuevo, con su anuncio nuevo y no con la vida del creativo: gana si su anuncio sale PROVEN (o lo
 *   fue y ahora falla); pierde si sale perdedor o se apagó sin ganar. Si pierde, ahí no se vuelve a revivir.
 * - Candidato (dueño: "20+ compras y CPA de toda su vida abajo del promedio entre el C y el BE"): apagado en la campaña, 14 días
 *   sin gastar ahí, 20 compras o más en su vida ahí y su CPA de toda su vida ahí debajo del punto medio entre la C y el BE.
 *   Uno a la vez por ciudad: con un revivido en test, ninguno más. Entre varios, el que más dólares dejó.
 * - Dónde (dueño: "Mixto"): en su mismo conjunto si ahí ya no queda nada prendido; si no, en un conjunto nuevo, para no moverle
 *   el aprendizaje al que funciona ni quedarse sin gasto.
 */
final class Revival
{
    /** Las del anuncio revivido que cuentan como ganar ("falla" es que ya ganó). */
    public const WON = ['proven', 'failing'];

    /**
     * Los anuncios revividos de cada creativo en cada campaña y cómo les fue.
     *
     * @param array<string, array> $ads $f['ads'] (creative_id, campaign_meta_id, created_time, active)
     * @param array<string, array> $verdicts lo que dicen las reglas de cada anuncio (key)
     * @param array<string, array<string, array{spend: float, purchases: float}>> $daily el gasto de cada anuncio por día
     * @return list<array{creative_id: int, campaign_meta_id: string, ad: string, since: string, rested: int, result: string}>
     */
    public static function history(array $ads, array $verdicts, array $daily, int $restDays): array
    {
        $groups = [];
        foreach ($ads as $mid => $a) {
            if (($a['creative_id'] ?? null) !== null && ($a['created_time'] ?? null) !== null) {
                $groups[(int) $a['creative_id']][(string) $a['campaign_meta_id']][] = (string) $mid;
            }
        }
        $out = [];
        foreach ($groups as $cid => $byCampaign) {
            foreach ($byCampaign as $campaignId => $mids) {
                foreach ($mids as $mid) {
                    $born = substr((string) $ads[$mid]['created_time'], 0, 10);
                    $last = null;
                    foreach ($mids as $other) {
                        foreach ($other === $mid ? [] : ($daily[$other] ?? []) as $date => $row) {
                            if ($date < $born && $row['spend'] > 0 && ($last === null || $date > $last)) {
                                $last = $date;
                            }
                        }
                    }
                    if ($last === null || self::days($last, $born) < $restDays) {
                        continue;
                    }
                    $key = $verdicts[$mid]['key'] ?? null;
                    $out[] = ['creative_id' => $cid, 'campaign_meta_id' => (string) $campaignId, 'ad' => $mid, 'since' => $born, 'rested' => self::days($last, $born),
                        'result' => in_array($key, self::WON, true) ? 'won' : ($key === 'loser' || !($ads[$mid]['active'] ?? false) ? 'lost' : 'testing')];
                }
            }
        }

        return $out;
    }

    /**
     * El ganador que se revive hoy en una campaña, o null.
     *
     * @param array $f los datos del producto (campaigns, adsets, ads, creatives)
     * @param list<array> $revivals self::history
     * @param array<string, string> $dismissed las misiones con "No estoy de acuerdo"
     * @return array{creative_id: int, label: string, ad: string, adset: ?string, adset_active: bool, where: string, purchases: float, spend: float,
     *     cpa: float, limit: float, c: float, be: float, gain: float, last_spend: string, rest_days: int}|null
     */
    public static function candidate(array $f, array $daily, string $campaignId, array $revivals, array $s, string $today, array $dismissed = []): ?array
    {
        $c = $f['campaigns'][$campaignId] ?? null;
        if (!$c || !($c['active'] ?? false) || !($c['target'] ?? null) || !($c['break_even'] ?? null)) {
            return null;
        }
        $lost = [];
        foreach ($revivals as $r) {
            if ($r['campaign_meta_id'] !== $campaignId) {
                continue;
            }
            if ($r['result'] === 'testing') {
                return null; // uno a la vez
            }
            if ($r['result'] === 'lost') {
                $lost[$r['creative_id']] = true;
            }
        }
        $limit = round(((float) $c['target'] + (float) $c['break_even']) / 2, 2);
        $productId = (int) ($f['product']['id'] ?? 0);
        $best = null;
        foreach ($f['creatives'] as $cid => $cr) {
            $mids = array_values(array_filter(array_map('strval', $cr['ads'] ?? []), fn ($m) => ($f['ads'][$m]['campaign_meta_id'] ?? null) === $campaignId));
            if (!$mids || isset($lost[(int) $cid]) || isset($dismissed["{$productId}:{$campaignId}:c{$cid}:revive"])
                || array_filter($mids, fn ($m) => $f['ads'][$m]['active'] ?? false)) {
                continue;
            }
            $spend = 0.0;
            $purchases = 0.0;
            $last = null;
            $lastOf = [];
            foreach ($mids as $m) {
                foreach ($daily[$m] ?? [] as $date => $row) {
                    $spend += $row['spend'];
                    $purchases += $row['purchases'];
                    if ($row['spend'] > 0) {
                        $last = $last === null || $date > $last ? $date : $last;
                        $lastOf[$m] = !isset($lastOf[$m]) || $date > $lastOf[$m] ? $date : $lastOf[$m];
                    }
                }
            }
            if ($last === null || $purchases < (float) $s['revive_min_purchases'] || $spend / $purchases >= $limit
                || self::days($last, $today) < (int) $s['revive_rest_days']) {
                continue;
            }
            // El anuncio que se duplica: el último que gastó y que todavía está en Meta
            $template = collect($lastOf)->filter(fn ($d, $m) => !($f['ads'][$m]['missing'] ?? false))->sortDesc()->keys()->first();
            if ($template === null) {
                continue;
            }
            $gain = $purchases * (float) $c['break_even'] - $spend;
            if ($best !== null && $gain <= $best['gain']) {
                continue;
            }
            $setId = (string) $f['ads'][$template]['adset_meta_id'];
            $set = $f['adsets'][$setId] ?? null;
            $busy = (bool) array_filter($f['ads'], fn ($a) => (string) $a['adset_meta_id'] === $setId && ($a['active'] ?? false));
            $best = ['creative_id' => (int) $cid, 'label' => (string) $cr['label'], 'ad' => (string) $f['ads'][$template]['name'],
                'adset' => $set['name'] ?? null, 'adset_active' => (bool) ($set['active'] ?? false),
                'where' => $busy || !$set || ($set['missing'] ?? false) ? 'new' : 'same',
                'purchases' => $purchases, 'spend' => round($spend, 2), 'cpa' => round($spend / $purchases, 2), 'limit' => $limit,
                'c' => (float) $c['target'], 'be' => (float) $c['break_even'], 'gain' => round($gain, 2), 'last_spend' => $last, 'rest_days' => self::days($last, $today)];
        }

        return $best;
    }

    /**
     * Cuántos revividos volvieron a ganar, contra los creativos nuevos de los mismos días (sus anuncios de los últimos 60 días
     * que ya se juzgaron: ganadores o perdedores).
     *
     * @param list<array> $revivals self::history
     * @return array{won: int, lost: int, testing: int, new_won: int, new_lost: int}
     */
    public static function score(array $revivals, array $ads, array $verdicts, string $today, int $days = 60): array
    {
        $out = ['won' => 0, 'lost' => 0, 'testing' => 0, 'new_won' => 0, 'new_lost' => 0];
        foreach ($revivals as $r) {
            $out[$r['result']]++;
        }
        $revived = array_flip(array_column($revivals, 'ad'));
        $from = date('Y-m-d', strtotime("{$today} -{$days} days"));
        foreach ($ads as $mid => $a) {
            if (isset($revived[(string) $mid]) || ($a['creative_id'] ?? null) === null || substr((string) ($a['created_time'] ?? ''), 0, 10) < $from) {
                continue;
            }
            $key = $verdicts[$mid]['key'] ?? null;
            if (in_array($key, self::WON, true)) {
                $out['new_won']++;
            } elseif ($key === 'loser') {
                $out['new_lost']++;
            }
        }

        return $out;
    }

    /** Días entre dos fechas (Y-m-d). */
    public static function days(string $from, string $to): int
    {
        return (int) round((strtotime(substr($to, 0, 10)) - strtotime(substr($from, 0, 10))) / 86400);
    }
}
