<?php

use App\Models\MetaAdAccount;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaInsightMapper;
use App\Services\Meta\MetaInsightsSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

// .168 (dueño, 2026-11-21): en las campañas marcadas "Purchase al entregar", Meta recibe el Purchase solo de los entregados y
// los pagados por adelantado. Para que el C, el BE y todas las reglas las sigan midiendo por pedidos (como a las demás), en
// sus días marcados las compras son el "pedido hecho" (AddPaymentInfo), y el Purchase de Meta queda en real_purchases.

beforeEach(function () {
    $insights = function ($t) {
        $t->id();
        foreach (['meta_ad_account_id', 'impressions', 'reach', 'clicks', 'link_clicks', 'unique_link_clicks', 'outbound_clicks', 'landing_page_views',
            'video_plays', 'video_3s_plays', 'thruplays', 'video_p25', 'video_p50', 'video_p75', 'video_p95', 'video_p100'] as $c) {
            $t->unsignedBigInteger($c)->nullable();
        }
        foreach (['campaign_meta_id', 'adset_meta_id', 'ad_meta_id'] as $c) {
            $t->string($c)->nullable();
        }
        foreach (['spend', 'frequency', 'purchases', 'purchase_value', 'real_purchases', 'video_avg_time'] as $c) {
            $t->decimal($c, 14, 4)->nullable();
        }
        $t->string('level');
        $t->string('meta_id');
        $t->json('actions')->nullable();
        $t->timestamp('synced_at')->nullable();
        $t->timestamps();
    };
    Schema::create('meta_insights_daily', function ($t) use ($insights) {
        $insights($t);
        $t->date('date');
    });
    Schema::create('meta_insights_current', function ($t) use ($insights) {
        $insights($t);
        $t->string('range');
        $t->date('date_start')->nullable();
        $t->date('date_stop')->nullable();
    });
    Schema::create('meta_campaigns', fn ($t) => [$t->id(), $t->unsignedBigInteger('meta_ad_account_id')->nullable(), $t->string('meta_id'), $t->string('name')->nullable(),
        $t->unsignedBigInteger('product_id')->nullable(), $t->timestamp('missing_since')->nullable(), $t->timestamps()]);
    Schema::create('meta_purchase_modes', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id')->nullable(), $t->string('campaign_meta_id')->nullable(), $t->date('starts_on'),
        $t->date('ends_on')->nullable(), $t->timestamps()]);
    DB::table('meta_campaigns')->insert([['meta_id' => '555', 'name' => 'CERO CCS al entregar'], ['meta_id' => '777', 'name' => 'CERO CCS']]);
    DB::table('meta_purchase_modes')->insert(['product_id' => 7, 'campaign_meta_id' => '555', 'starts_on' => '2026-11-20']);
    $this->account = (new MetaAdAccount())->forceFill(['id' => 1, 'meta_id' => '999']);
});

/** Una fila de Meta: Purchase y "pedido hecho" (AddPaymentInfo) de una campaña. */
function fila168(string $campaign, string $from, string $to, int $purchases, int $placed): array
{
    return ['campaign_id' => $campaign, 'campaign_name' => "C{$campaign}", 'date_start' => $from, 'date_stop' => $to, 'spend' => '30.00', 'impressions' => '1000',
        'actions' => [['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => (string) $purchases],
            ['action_type' => 'offsite_conversion.fb_pixel_add_payment_info', 'value' => (string) $placed]],
        'action_values' => [['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => (string) ($purchases * 30)],
            ['action_type' => 'offsite_conversion.fb_pixel_add_payment_info', 'value' => (string) ($placed * 30)]]];
}

it('el mapeo cuenta como compra la acción que se le pida, y guarda el Purchase aparte', function () {
    $row = fila168('555', '2026-11-20', '2026-11-20', 2, 5);

    expect(MetaInsightMapper::map($row))->toMatchArray(['purchases' => 2.0, 'purchase_value' => 60.0, 'real_purchases' => null])
        ->and(MetaInsightMapper::map($row, MetaInsightMapper::PLACED_ACTIONS))->toMatchArray(['purchases' => 5.0, 'purchase_value' => 150.0, 'real_purchases' => 2.0]);
});

it('los días marcados de la campaña cuentan pedidos; los de antes y las demás campañas, el Purchase como siempre', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
        fila168('555', '2026-11-19', '2026-11-19', 3, 3),
        fila168('555', '2026-11-20', '2026-11-20', 2, 5),
        fila168('777', '2026-11-20', '2026-11-20', 4, 4),
    ]])]);

    (new MetaInsightsSync())->daily($this->account, new MetaClient('prueba'), 'campaign', '2026-11-19', '2026-11-20');
    $day = fn (string $id, string $date) => DB::table('meta_insights_daily')->where('meta_id', $id)->where('date', $date)->first();

    expect([(float) $day('555', '2026-11-19')->purchases, $day('555', '2026-11-19')->real_purchases])->toBe([3.0, null])
        ->and([(float) $day('555', '2026-11-20')->purchases, (float) $day('555', '2026-11-20')->real_purchases])->toBe([5.0, 2.0])
        ->and([(float) $day('777', '2026-11-20')->purchases, $day('777', '2026-11-20')->real_purchases])->toBe([4.0, null]);
});

it('un rango fijo de la campaña marcada suma sus días (ya contados por pedidos); el de otra campaña queda como lo da Meta', function () {
    Http::fake(['graph.facebook.com/*' => Http::sequence()
        ->push(['data' => [fila168('555', '2026-11-19', '2026-11-19', 3, 3), fila168('555', '2026-11-20', '2026-11-20', 2, 5)]])
        ->push(['data' => [fila168('555', '2026-11-14', '2026-11-20', 5, 8), fila168('777', '2026-11-14', '2026-11-20', 6, 6)]])]);
    $sync = new MetaInsightsSync();
    $sync->daily($this->account, new MetaClient('prueba'), 'campaign', '2026-11-19', '2026-11-20');
    $sync->preset($this->account, new MetaClient('prueba'), 'campaign', 'last_7d');
    $range = fn (string $id) => DB::table('meta_insights_current')->where('meta_id', $id)->where('range', 'last_7d')->first();

    // 3 pedidos del 19 (antes de marcarla) + 5 del 20; Meta vio 5 Purchase en el rango
    expect([(float) $range('555')->purchases, (float) $range('555')->real_purchases])->toBe([8.0, 5.0])
        ->and([(float) $range('777')->purchases, $range('777')->real_purchases])->toBe([6.0, null]);
});

it('.171: con el producto prendido, todas sus campañas (también una nueva) cuentan pedidos desde ese día; las de otro producto, no', function () {
    DB::table('meta_purchase_modes')->delete();
    DB::table('meta_purchase_modes')->insert(['product_id' => 7, 'campaign_meta_id' => null, 'starts_on' => '2026-11-20']);
    DB::table('meta_campaigns')->where('meta_id', '777')->update(['product_id' => 7]);
    DB::table('meta_campaigns')->insert([['meta_id' => '888', 'name' => 'CERO resto del país', 'product_id' => 7], ['meta_id' => '999', 'name' => 'OTRO', 'product_id' => 8]]);
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
        fila168('777', '2026-11-19', '2026-11-19', 3, 3),
        fila168('777', '2026-11-20', '2026-11-20', 2, 5),
        fila168('888', '2026-11-20', '2026-11-20', 1, 4),
        fila168('999', '2026-11-20', '2026-11-20', 4, 6),
    ]])]);

    (new MetaInsightsSync())->daily($this->account, new MetaClient('prueba'), 'campaign', '2026-11-19', '2026-11-20');
    $day = fn (string $id, string $date) => DB::table('meta_insights_daily')->where('meta_id', $id)->where('date', $date)->first();

    expect([(float) $day('777', '2026-11-19')->purchases, $day('777', '2026-11-19')->real_purchases])->toBe([3.0, null])
        ->and([(float) $day('777', '2026-11-20')->purchases, (float) $day('777', '2026-11-20')->real_purchases])->toBe([5.0, 2.0])
        ->and([(float) $day('888', '2026-11-20')->purchases, (float) $day('888', '2026-11-20')->real_purchases])->toBe([4.0, 1.0])
        ->and([(float) $day('999', '2026-11-20')->purchases, $day('999', '2026-11-20')->real_purchases])->toBe([4.0, null]);
});
