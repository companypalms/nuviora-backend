<?php

use App\Models\CreativeBatch;
use App\Models\MetaAnalysis;
use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\AnalysisSender;
use App\Services\Meta\Analysis\CreativeCalls;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\MetaMetrics;
use App\Services\Meta\Queue\BatchQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// .119 (dueño, 2026-11-09): "por qué en ZenPulse Caracas no me mandó a apagar esos creativos que tienen el CPA muy alto…
// nunca podemos permitirnos perder dinero sin una razón buena justificada". Los números son los de producción de ese día.

const ZEN_CARACAS = '120000000000000061';

/** Por creativo: nombre, batch, [vida, 7 días, 3 días] (gasto, compras) y su historia por día (día => gasto, compras). */
function zenCaracasCreatives(): array
{
    $days = ['10-27', '10-28', '10-29', '10-30', '10-31', '11-01', '11-02', '11-03', '11-04', '11-05', '11-06', '11-07', '11-08'];
    $rows = fn (array $n) => array_combine(array_map(fn ($d) => "2026-{$d}", $days), array_map(fn ($p) => ['spend' => $p[0], 'purchases' => $p[1]], $n));

    return [
        5 => ['CREATIVO 5 27/10/2026', 'A', [216.89, 48, 118.61, 17, 51.87, 5], $rows([[5.04, 3], [18.35, 5], [18.81, 7], [17.49, 6], [14.59, 3], [19.39, 5], [18.66, 5], [18.33, 4], [15.49, 0], [14.26, 3], [16.07, 2], [17.72, 1], [18.08, 2]])],
        4 => ['CREATIVO 4 27/10/2026', 'A', [54.45, 11, 45.39, 9, 26.19, 2], $rows([[.4, 0], [0, 0], [1.02, 0], [1.12, 1], [1.64, 1], [2.5, 0], [1.68, 2], [3.02, 0], [7.3, 3], [7.2, 2], [4.16, 1], [9.75, 0], [12.28, 1]])],
        8 => ['CREATIVO 8 27/10/2026', 'B', [36.96, 8, 17.57, 3, 9.22, 1], $rows([[5.55, 4], [2.76, 0], [3.12, 1], [1.68, 0], [2.32, 0], [3.31, 0], [1.64, 0], [2.51, 0], [1.96, 2], [2.24, 0], [3.72, 0], [2.11, 1], [3.39, 0]])],
        6 => ['CREATIVO 6 27/10/2026', 'B', [55.72, 20, 24.35, 11, 12.52, 5], $rows([[8.7, 2], [8.31, 2], [4.32, 2], [2.96, 2], [3.37, 0], [3.15, 1], [3.1, 2], [3.86, 2], [1.72, 1], [3.15, 1], [4.93, 1], [2.56, 1], [5.03, 3]])],
    ];
}

function zenCaracasMeta(array $off = [], array $noSpend = []): array
{
    $w = function (float $spend, float $purchases) {
        $t = MetaMetrics::sum([['spend' => $spend, 'purchases' => $purchases, 'impressions' => 2000]]);

        return ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];
    };
    $windows = fn (array $n) => ['max' => $w($n[0], $n[1]), '7d' => $w($n[2], $n[3]), '3d' => $w($n[4], $n[5]), 'prev' => $w(0, 0), 'yesterday' => $w(0, 0)];
    $id = ZEN_CARACAS;
    $out = ['product' => ['id' => 1149, 'name' => 'ZenPulse', 'mode' => 'normal'], 'dates' => AdFacts::dates(), 'adsets' => [], 'ads' => [], 'creatives' => [],
        'campaigns' => [$id => ['meta_id' => $id, 'name' => 'ZENPULSE | CARACAS', 'city_id' => 1, 'city' => 'Distrito Capital', 'active' => true, 'daily_budget' => 40.0,
            'target' => 4.44, 'break_even' => 7.61, 'target_source' => 'financial', 'target_no_cost' => false, 'windows' => $windows([1500, 300, 300, 50, 100, 16])]]];
    foreach (['A', 'B'] as $set) {
        $out['adsets']["{$id}{$set}"] = ['meta_id' => "{$id}{$set}", 'name' => "BATCH {$set}", 'campaign_meta_id' => $id, 'active' => true, 'missing' => false,
            'batch_code' => null, 'angle_code' => null, 'concept' => null, 'stage' => null, 'windows' => []];
    }
    foreach (zenCaracasCreatives() as $cid => [$label, $set, $n]) {
        $adId = "{$id}{$cid}";
        if (in_array($cid, $noSpend, true)) {
            [$n[2], $n[3], $n[4], $n[5]] = [0, 0, 0, 0];
        }
        $out['creatives'][$cid] = ['id' => $cid, 'label' => $label, 'type' => 'video', 'status' => null, 'status_source' => null, 'angle_code' => null,
            'batch_code' => null, 'creative_code' => null, 'iteration_of' => null, 'ads' => [$adId]];
        $out['ads'][$adId] = ['id' => $cid, 'meta_id' => $adId, 'name' => $label, 'campaign_meta_id' => $id, 'adset_meta_id' => "{$id}{$set}", 'creative_id' => $cid,
            'creative_type' => 'video', 'video_length' => null, 'angle_code' => null, 'batch_code' => null, 'active' => !in_array($cid, $off, true),
            'created_time' => '2026-10-26 15:41:23', 'windows' => $windows($n)];
    }

    return $out;
}

function zenCaracasDecision(array $off = [], array $noSpend = [], bool $history = true): array
{
    $facts = Mockery::mock(AdFacts::class);
    $facts->allows('load')->andReturn([1149 => zenCaracasMeta($off, $noSpend)]);
    $daily = [];
    foreach (zenCaracasCreatives() as $cid => $c) {
        $daily[ZEN_CARACAS . $cid] = $history ? $c[3] : [];
    }
    $facts->allows('dailyHistory')->andReturn($daily);
    $settings = Mockery::mock(DecisionSettings::class);
    $settings->allows('get')->andReturn(DecisionSettings::defaults());
    $empty = ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0];
    $city = ['available' => true, 'stock' => 100, 'units_per_day' => 5.0, 'days_of_stock' => 20.0, 'sizes_out' => [], 'version' => null];
    $ops = Mockery::mock(OpsFacts::class);
    $ops->allows('campaigns')->andReturn(['history_since' => null, 'today' => now()->toDateString(), 'yesterday' => now()->subDay()->toDateString(), 'campaigns' => [ZEN_CARACAS => $empty]]);
    $ops->allows('products')->andReturn([1149 => ['price' => 25.0, 'price_change' => null, 'cities' => [1 => $city], 'version' => null, 'bank' => ['codes' => [], 'ready' => [], 'launched' => []]]]);

    return (new MetaDecisions($facts, $settings, $ops))->compute([1149])[1149];
}

beforeEach(function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 08:10:00'));
});

describe('.119: ZenPulse en Caracas, con los números del 9/11', function () {
    it('el 5 y el 4 se apagan ahí; el 8 y el 6 siguen', function () {
        $d = zenCaracasDecision();
        $here = fn (int $id) => $d['creatives'][$id]['cities'][0];

        expect($here(5)['key'])->toBe('failing')->and($here(5)['stop'])->toBeTrue()
            ->and($here(5)['data'])->toBe('3 días: gastó $51,87 (11,7×C) · 5 compras · CPA $10,37 · C $4,44 · BE $7,61')
            ->and($here(4)['stop'])->toBeTrue()
            ->and($here(8)['key'])->toBe('failing')->and($here(8))->not->toHaveKey('stop')
            ->and($here(6)['key'])->toBe('proven')->and($here(6))->not->toHaveKey('stop')
            ->and(CreativeCalls::stopIn($d['creatives'][5]))->toBe(['Distrito Capital'])
            ->and(CreativeCalls::stopIn($d['creatives'][8]))->toBe([]);
    });

    it('sigue siendo ganador: no pasa a perdedor ni se apaga en todas', function () {
        $d = zenCaracasDecision();
        $facts = AnalysisFacts::build([1149 => $d]);

        expect($d['creatives'][5]['verdict']['key'])->toBe('failing')
            ->and($facts[0]['stop_all'])->toBe([])
            ->and(MetaDecisions::autoStatus($d['creatives'][5], '2026-11-08', DecisionSettings::defaults(), '2026-11-09')[0])->toBe('winner');
    });

    it('el análisis manda a apagarlos en la ciudad y a iterarlos', function () {
        $facts = AnalysisFacts::build([1149 => zenCaracasDecision()]);
        $call = fn (int $id) => collect($facts[0]['creatives'])->firstWhere('id', $id)['call'];
        $stop = collect(UrgentList::build($facts))->where('kind', 'stop_creatives')->values();

        expect($facts[0]['stop_by_city'])->toBe(['Distrito Capital' => ['CREATIVO 5 27/10/2026', 'CREATIVO 4 27/10/2026']])
            ->and($call(5)['label'])->toBe('ITERAR · PARAR EN DISTRITO CAPITAL')
            ->and($call(5)['todo'])->toBe('Apágalo en Distrito Capital (ahí pierde). Fue ganador: pide hooks nuevos.')
            ->and($call(4)['editor'])->toStartWith('Qué hacer: Este creativo vendía y dejó de vender')
            ->and($call(8)['stop_in'])->toBe([])
            ->and($stop)->toHaveCount(1)
            ->and($stop[0]['title'])->toBe('Apaga 2 creativos que aquí pierden')
            ->and($stop[0]['detail'][0])->toBe('CREATIVO 5 27/10/2026 en Distrito Capital: 3 días: gastó $51,87 (11,7×C) · 5 compras · CPA $10,37 · C $4,44 · BE $7,61');
    });

    it('cuentan como apagados para la ciudad: la lista de podar y los batches vivos', function () {
        $n = zenCaracasDecision()['campaigns'][ZEN_CARACAS]['numbers'];

        expect($n['prune'])->toBe(['CREATIVO 5 27/10/2026', 'CREATIVO 4 27/10/2026'])
            ->and($n['live'])->toBe(1); // el batch A se queda sin anuncios; el B sigue con el 8 y el 6
    });
});

describe('.119: un ganador ya apagado sigue en la fila de iteraciones', function () {
    it('va para el editor líder, pero no sale en el análisis ni se manda a apagar otra vez', function () {
        $facts = AnalysisFacts::build([1149 => zenCaracasDecision([5])]);

        expect(array_column($facts[0]['off_winners'], 'id'))->toBe([5])
            ->and($facts[0]['off_winners'][0]['call']['key'])->toBe('iterate')
            ->and($facts[0]['off_winners'][0]['call']['editor'])->toStartWith('Qué hacer: Este creativo vendía y dejó de vender')
            ->and(array_column($facts[0]['creatives'], 'id'))->not->toContain(5)
            ->and($facts[0]['stop_by_city']['Distrito Capital'])->toBe(['CREATIVO 4 27/10/2026'])
            ->and(array_column(array_column(AnalysisSender::candidates($facts), 'creative'), 'id'))->toContain(5);
    });

    it('solo si fue ganador y gastó en los últimos 7 días', function () {
        expect(AnalysisFacts::build([1149 => zenCaracasDecision([5], [5])])[0]['off_winners'])->toBe([])
            ->and(AnalysisFacts::build([1149 => zenCaracasDecision([5], [], false)])[0]['off_winners'])->toBe([]);
    });

    it('si sus iteraciones o su ángulo descansan, no', function () {
        $d = zenCaracasDecision([5]);
        $d['creatives'][5]['rest'] = 'No pidas más versiones de este creativo.';

        expect(AnalysisFacts::build([1149 => $d])[0]['off_winners'])->toBe([]);
    });

    it('no se le pide otra iteración si ya se le hizo una en estos días', function () {
        Schema::dropIfExists('creative_batches');
        Schema::create('creative_batches', function ($t) {
            $t->id();
            $t->unsignedInteger('number')->nullable();
            $t->string('status');
            $t->unsignedBigInteger('reference_creative_id')->nullable();
            $t->date('due_date')->nullable();
            $t->timestamps();
        });
        $row = fn (int $number, string $status, int $ago) => DB::table('creative_batches')->insert(['number' => $number, 'status' => $status, 'reference_creative_id' => 5,
            'created_at' => now()->subDays($ago), 'updated_at' => now()->subDays($ago)]);
        $settings = Mockery::mock(DecisionSettings::class);
        $settings->allows('get')->andReturn(DecisionSettings::defaults());
        $queue = Mockery::mock(BatchQueue::class);
        $queue->allows('approve')->andReturnUsing(fn (CreativeBatch $b) => $b);
        $sent = fn (array $extra) => (new AnalysisSender($settings, $queue))->fill(new MetaAnalysis(['facts' => [['product_id' => 1149, 'mode' => 'normal', 'urgency' => ['level' => 2], 'creatives' => [],
            'off_winners' => [$extra + ['id' => 5, 'label' => 'CREATIVO 5', 'verdict_key' => 'failing', 'call' => ['key' => 'iterate', 'editor' => 'Haz hooks nuevos.']]]]]]), [1149 => ['2026-11-11']])['sent'];

        // Una que se testeó hace 30 días no cuenta: se aprueba la que estaba propuesta
        $row(200, 'tested', 30);
        $row(201, 'proposed', 0);
        expect($sent(['off_winner' => true]))->toBe(['B0201']);

        // Una que se testeó hace 3 días sí: para un ganador apagado no se pide otra…
        $row(202, 'tested', 3);
        expect($sent(['off_winner' => true]))->toBe([])
            // …pero a uno prendido se le sigue pidiendo como siempre
            ->and($sent([]))->toBe(['B0201']);
    });
});
