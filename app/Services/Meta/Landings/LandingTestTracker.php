<?php

namespace App\Services\Meta\Landings;

use App\Models\LandingTask;
use App\Models\MetaAdLandingChange;
use App\Services\Meta\Decisions\DecisionSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Los tests de las landings publicadas (Etapa 5, paso 6b). Corre con la sincronización de cada hora: para cada encargo
 * "En test" junta los mismos anuncios antes y después (LandingTestRules::windows) y deja el resultado; cuando hay
 * veredicto pasa solo a "Testeado".
 *
 * Los mismos anuncios: los del producto que después de publicar llevaban a la landing del test y que antes ya tenían
 * visitas (a la misma landing, si se cambió en su lugar, o a la vieja, si es una URL nueva). Antes se suman todos sus
 * días; después, solo los días en que llevaban a la landing del test.
 */
class LandingTestTracker
{
    public function __construct(private DecisionSettings $settings = new DecisionSettings())
    {
    }

    /** @return int cuántos encargos pasaron a "Testeado" */
    public function run(?Carbon $now = null): int
    {
        $now ??= now();
        $done = 0;
        foreach (LandingTask::where('status', 'testing')->whereNotNull('published_at')->whereNotNull('test_landing_id')->get() as $task) {
            $result = $this->evaluate($task, $now);
            $task->forceFill(['result' => $result] + ($result['done'] ? ['status' => 'tested', 'tested_at' => $now, 'status_at' => $now, 'status_by' => null] : []))->save();
            $done += $result['done'] ? 1 : 0;
        }

        return $done;
    }

    public function evaluate(LandingTask $task, Carbon $now): array
    {
        $s = $this->settings->get();
        $tz = config('app.timezone');
        $w = LandingTestRules::windows($task->published_at->copy()->setTimezone($tz)->toDateString(), $now->copy()->setTimezone($tz)->subDay()->toDateString(), $s);

        $ads = DB::table('meta_ads as a')->join('meta_campaigns as c', 'c.meta_id', '=', 'a.campaign_meta_id')
            ->where('c.product_id', $task->product_id)->get(['a.id', 'a.meta_id'])->keyBy('meta_id');
        $changes = MetaAdLandingChange::whereIn('meta_ad_id', $ads->pluck('id'))->orderBy('changed_at')->orderBy('id')->get(['meta_ad_id', 'landing_id', 'changed_at'])
            ->groupBy('meta_ad_id')->map(fn ($rows) => $rows->map(fn ($r) => [(int) $r->landing_id, $r->changed_at->format('Y-m-d H:i:s')])->values()->all())->all();
        $rows = $ads->isEmpty() ? collect() : DB::table('meta_insights_daily')->where('level', 'ad')->whereIn('ad_meta_id', $ads->keys())
            ->whereBetween('date', [$w['before'][0], max($w['after'][1], $w['before'][1])])
            ->get(['date', 'ad_meta_id', 'spend', 'impressions', 'link_clicks', 'landing_page_views', 'purchases']);

        $landingId = (int) $task->test_landing_id;
        $before = [];
        $after = [];
        foreach ($rows as $r) {
            $date = substr((string) $r->date, 0, 10);
            $adId = $ads[$r->ad_meta_id]->id;
            if ($date >= $w['before'][0] && $date <= $w['before'][1]) {
                $before[$r->ad_meta_id][] = (array) $r;
            } elseif ($date >= $w['after'][0] && $date <= $w['after'][1] && LandingRules::at($changes[$adId] ?? [], $date) === $landingId) {
                $after[$r->ad_meta_id][] = (array) $r;
            }
        }
        $same = array_filter(array_keys($after), fn ($ad) => array_sum(array_column($before[$ad] ?? [], 'landing_page_views')) > 0);
        $tb = LandingRules::empty();
        $ta = LandingRules::empty();
        foreach ($same as $ad) {
            foreach ($before[$ad] as $row) {
                LandingRules::add($tb, $row, null);
            }
            foreach ($after[$ad] as $row) {
                LandingRules::add($ta, $row, null);
            }
        }

        return LandingTestRules::evaluate($tb, $ta, $w['after_days'], $s) + [
            'ads' => count($same), 'before_from' => $w['before'][0], 'before_to' => $w['before'][1],
            'after_from' => $w['after'][0], 'after_to' => $w['after'][1], 'after_days' => $w['after_days'], 'evaluated_at' => $now->toIso8601String(),
        ];
    }
}
