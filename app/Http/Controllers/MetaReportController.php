<?php

namespace App\Http\Controllers;

use App\Models\MetaAdAccount;
use App\Models\MetaAdset;
use App\Models\MetaCampaign;
use App\Models\MetaConnection;
use App\Models\MetaCreative;
use App\Services\Meta\MetaAdsReport;
use App\Services\Meta\MetaVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * La pantalla Meta Ads (documento de Fran del 2026-10-08, Módulo 2, §26 a §31, §33, §60): todo desde nuestra base de
 * datos, sin llamar a Meta. Solo el Administrador (§44); las respuestas pasan por el filtro de datos financieros (§45).
 */
class MetaReportController extends Controller
{
    /** §29, §31, §60: por producto → ciudad, con el desglose por cuenta y lo que falta clasificar (§13). */
    public function overview(Request $request)
    {
        $report = $this->report($request);
        $data = $report->overview($request->boolean('compare'));

        return $this->json($data + $this->rangeInfo($report) + [
            'pending_classification' => MetaCampaign::whereHas('account', fn ($q) => $q->where('is_active', true))
                ->where(fn ($q) => $q->whereNull('product_id')->orWhereNull('city_id'))->count(),
            'last_sync_at' => MetaConnection::max('last_success_at'),
            'has_connections' => MetaConnection::exists(),
        ]);
    }

    /** §29: campañas, ad sets, anuncios o creativos (§33) de un filtro (§30). */
    public function rows(Request $request)
    {
        $level = in_array($request->get('level'), ['campaign', 'adset', 'ad', 'creative'], true) ? $request->get('level') : 'campaign';
        $report = $this->report($request);

        return $this->json(['level' => $level, 'rows' => $report->rows($level, $request->boolean('compare'))] + $this->rangeInfo($report));
    }

    /** §26: una métrica en el tiempo. */
    public function series(Request $request)
    {
        $metric = (string) $request->get('metric', 'cpa');
        $report = $this->report($request);

        return $this->json($report->series($metric, $request->boolean('compare')) + $this->rangeInfo($report));
    }

    /** §33 y §60: "entrar en C004": el agregado, los anuncios donde se usa y su estado. */
    public function creative(Request $request, MetaCreative $creative)
    {
        $request->merge(['creative' => $creative->id]);
        $report = $this->report($request);
        $compare = $request->boolean('compare');
        $summary = collect($report->rows('creative', $compare))->firstWhere('id', $creative->id);

        $adsReport = new MetaAdsReport(['creative' => $creative->id, 'include_inactive' => true], $report->range, $report->from, $report->to);

        // Paso 1a: lo que dice el anuncio y a dónde lleva (del anuncio más nuevo), y las landings y UTM de todos
        $ads = \App\Models\MetaAd::where('meta_creative_ref_id', $creative->id)->orderByDesc('created_time')
            ->get(['ad_body', 'ad_title', 'image_url', 'thumbnail_url', 'landing_url', 'url_tags', 'video_length']);
        $latest = $ads->first();
        $details = [
            'title' => $latest?->ad_title, 'body' => $latest?->ad_body, 'image_url' => $latest?->image_url ?: $latest?->thumbnail_url,
            'video_length' => $ads->pluck('video_length')->filter()->max(),
            'landing_urls' => $ads->pluck('landing_url')->filter()->unique()->values()->all(),
            'url_tags' => $ads->pluck('url_tags')->filter()->unique()->values()->all(),
            'synced' => $ads->contains(fn ($a) => $a->ad_body !== null || $a->landing_url !== null || $a->url_tags !== null),
        ];

        // Paso 1b: el producto, el ángulo (con su nombre si ya lo tiene), el batch y el padre de una iteración
        $angle = $creative->product_id && $creative->angle_code
            ? \App\Models\AdAngle::where('product_id', $creative->product_id)->where('code', $creative->angle_code)->first(['code', 'name', 'hypothesis', 'status'])
            : null;
        $parent = $creative->product_id && $creative->iteration_of
            // El mismo número puede estar en varios batches: primero el del mismo ángulo y el mismo batch
            ? MetaCreative::where('product_id', $creative->product_id)->where('creative_code', $creative->iteration_of)->has('ads')
                ->orderByRaw('(angle_code <=> ?) DESC, (batch_code <=> ?) DESC', [$creative->angle_code, $creative->batch_code])->orderByDesc('id')
                ->first(['id', 'label', 'tracking_id'])
            : null;

        return $this->json([
            'creative' => ['id' => $creative->id, 'tracking_id' => $creative->tracking_id, 'label' => $creative->displayName(),
                'type' => $creative->type, 'status' => $creative->status,
                'status_changed_at' => $creative->status_changed_at?->toIso8601String(),
                'product' => $creative->product ? ($creative->product->showable_name ?: $creative->product->title) : null,
                'angle_code' => $creative->angle_code, 'angle' => $angle?->only(['code', 'name', 'hypothesis', 'status']),
                'batch_code' => $creative->batch_code, 'iteration_of' => $creative->iteration_of,
                'parent' => $parent ? ['id' => $parent->id, 'label' => $parent->displayName()] : null],
            'details' => $details,
            'summary' => $summary,
            'ads' => $adsReport->rows('ad', $compare),
        ] + $this->rangeInfo($report));
    }

    /** Lo que se puede elegir en los filtros (§30). */
    public function filters()
    {
        return $this->json([
            'ranges' => collect(MetaAdsReport::RANGES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'accounts' => MetaAdAccount::where('is_active', true)->orderBy('name')->get(['id', 'name', 'meta_id']),
            'campaigns' => MetaCampaign::whereHas('account', fn ($q) => $q->where('is_active', true))->orderBy('name')
                ->get(['meta_id', 'name', 'product_id', 'city_id', 'effective_status', 'missing_since'])
                ->map(fn ($c) => ['meta_id' => $c->meta_id, 'name' => $c->name, 'product_id' => $c->product_id, 'city_id' => $c->city_id,
                    'active' => $c->effective_status === 'ACTIVE' && $c->missing_since === null]),
            'adsets' => MetaAdset::whereHas('account', fn ($q) => $q->where('is_active', true))->orderBy('name')
                ->get(['meta_id', 'name', 'campaign_meta_id', 'effective_status', 'missing_since'])
                ->map(fn ($s) => ['meta_id' => $s->meta_id, 'name' => $s->name, 'campaign_meta_id' => $s->campaign_meta_id,
                    'active' => $s->effective_status === 'ACTIVE' && $s->missing_since === null]),
            // Paso 1b: solo los que tienen anuncios (los viejos, de antes de separar por producto, dejan de verse)
            'creatives' => MetaCreative::has('ads')->orderBy('product_id')->orderBy('label')->get(['id', 'tracking_id', 'label', 'product_id', 'type', 'status'])
                ->map(fn (MetaCreative $c) => ['id' => $c->id, 'tracking_id' => $c->tracking_id, 'label' => $c->displayName(),
                    'product_id' => $c->product_id, 'type' => $c->type, 'status' => $c->status]),
        ]);
    }

    private function report(Request $request): MetaAdsReport
    {
        $int = fn ($k) => $request->filled($k) ? (int) $request->get($k) : null;
        $str = fn ($k) => $request->filled($k) ? (string) $request->get($k) : null;
        $filters = array_filter([
            'product_id' => $int('product_id'),
            'city_id' => $int('city_id'),
            'account_id' => $int('account_id'),
            'campaign' => $str('campaign'),
            'adset' => $str('adset'),
            'ad' => $str('ad'),
            'creative' => $int('creative'),
            'status' => in_array($request->get('status'), ['active', 'inactive'], true) ? $request->get('status') : null,
            'status_creative' => $str('status_creative'),
            'include_inactive' => $request->boolean('include_inactive') ?: null,
        ], fn ($v) => $v !== null);

        return new MetaAdsReport($filters, (string) $request->get('range', 'last_7d'), $str('from'), $str('to'));
    }

    private function rangeInfo(MetaAdsReport $r): array
    {
        return ['range' => ['key' => $r->range, 'label' => MetaAdsReport::rangeLabel($r->range), 'from' => $r->from, 'to' => $r->to,
            'prev_from' => $r->prevFrom, 'prev_to' => $r->prevTo]];
    }

    private function json(array $data)
    {
        return response()->json(MetaVisibility::forUser($data, Auth::user()) + ['can_see_financials' => MetaVisibility::canSeeFinancials(Auth::user())]);
    }
}
