<?php

use App\Services\Finance\ProfitAndLoss;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// .143 (dueño, 2026-11-14: "quiero saber si ese criterio realmente es bueno comparado a cómo eran nuestros resultados antes"):
// la radiografía antes / después de la IA de Meta Ads. Solo lee: lo que pasa adentro se deshace al final.

beforeEach(function () {
    $this->travelTo('2026-11-14 12:00:00');
    Schema::create('users', fn ($t) => [$t->id(), $t->string('names')->nullable()]);
    Schema::create('settings', fn ($t) => [$t->id(), $t->string('key')->unique(), $t->text('value')->nullable(), $t->timestamps()]);
    Schema::create('products', fn ($t) => [$t->id(), $t->string('title'), $t->string('showable_name')->nullable()]);
    Schema::create('cities', fn ($t) => [$t->id(), $t->string('name')]);
    Schema::create('statuses', fn ($t) => [$t->id(), $t->string('description')]);
    Schema::create('meta_campaigns', fn ($t) => [$t->id(), $t->string('meta_id'), $t->string('name')->nullable(), $t->unsignedBigInteger('product_id')->nullable(),
        $t->unsignedBigInteger('city_id')->nullable()]);
    Schema::create('meta_insights_daily', fn ($t) => [$t->id(), $t->date('date'), $t->string('level'), $t->string('meta_id'), $t->string('campaign_meta_id')->nullable(),
        $t->decimal('spend', 14, 2)->default(0), $t->decimal('purchases', 14, 4)->nullable()]);
    Schema::create('meta_creatives', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id')->nullable(), $t->string('label')->nullable()]);
    Schema::create('meta_ads', fn ($t) => [$t->id(), $t->string('meta_id'), $t->string('campaign_meta_id'), $t->unsignedBigInteger('meta_creative_ref_id')->nullable()]);
    Schema::create('target_cpa_versions', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('city_id')->nullable(),
        $t->decimal('target', 10, 2)->nullable(), $t->decimal('break_even', 10, 2)->nullable()]);
    Schema::create('order_financials', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->unsignedBigInteger('product_id')->nullable(),
        $t->unsignedBigInteger('city_id')->nullable(), $t->string('kind'), $t->date('created_date'), $t->string('outcome'), $t->unsignedBigInteger('status_id')->nullable()]);
    Schema::create('order_tracking_comprehensive_logs', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->unsignedBigInteger('to_status_id')->nullable(),
        $t->timestamp('created_at')->nullable()]);
    (require database_path('migrations/2026_10_30_500000_create_meta_analyses_table.php'))->up();
    (require database_path('migrations/2026_11_10_200000_create_meta_changes_table.php'))->up();
    (require database_path('migrations/2026_11_12_200000_make_meta_changes_campaign_nullable.php'))->up();

    DB::table('products')->insert(['id' => 1, 'title' => 'FLEXISTEP TOBILLERA', 'showable_name' => 'FlexiStep']);
    DB::table('cities')->insert(['id' => 1, 'name' => 'Caracas']);
    DB::table('statuses')->insert([['id' => 3, 'description' => 'Sin Stock'], ['id' => 16, 'description' => 'Entregado']]);
    DB::table('meta_campaigns')->insert(['meta_id' => 'c1', 'name' => 'FlexiStep Caracas', 'product_id' => 1, 'city_id' => 1]);
    DB::table('target_cpa_versions')->insert(['product_id' => 1, 'city_id' => 1, 'target' => 6, 'break_even' => 10]);

    $day = fn (string $from, string $to, string $level, string $id, float $spend, float $buys) => array_map(fn ($i) => ['date' => date('Y-m-d', strtotime("{$from} +{$i} days")),
        'level' => $level, 'meta_id' => $id, 'campaign_meta_id' => 'c1', 'spend' => $spend, 'purchases' => $buys], range(0, (strtotime($to) - strtotime($from)) / 86400));
    // la campaña: sin IA $20 por día con 2 compras (CPA $10 = el BE: no dejaba nada); con IA $30 con 4 (deja $10 por día)
    DB::table('meta_insights_daily')->insert([...$day('2026-10-12', '2026-11-01', 'campaign', 'c1', 20, 2), ...$day('2026-11-02', '2026-11-13', 'campaign', 'c1', 30, 4)]);
    // sus anuncios: sin IA, un perdedor que se apagó a los 4 días ($20 sin compras) y uno bueno
    DB::table('meta_insights_daily')->insert([...$day('2026-10-12', '2026-10-15', 'ad', 'viejo_perdedor', 5, 0), ...$day('2026-10-12', '2026-10-25', 'ad', 'viejo_bueno', 15, 2),
        // con IA: CRE LOSER (CPA $12, pierde) y otro bueno
        ...$day('2026-11-02', '2026-11-13', 'ad', 'a1', 6, 0.5), ...$day('2026-11-02', '2026-11-13', 'ad', 'a2', 24, 3.5)]);
    DB::table('meta_creatives')->insert(['id' => 9, 'product_id' => 1, 'label' => 'CRE LOSER']);
    DB::table('meta_ads')->insert(['meta_id' => 'a1', 'campaign_meta_id' => 'c1', 'meta_creative_ref_id' => 9]);

    // Tu día en Meta Ads: el 3/11, subir (la hizo) y apagar CRE LOSER en Caracas; el 4/11 lo mismo, y con este no estuvo de acuerdo
    $missions = [['key' => '1:c1:scale', 'kind' => 'scale', 'city' => 'Caracas', 'title' => 'Sube', 'steps' => ['Sube a $36']],
        ['key' => '1:stop:abc', 'kind' => 'stop_creatives', 'city' => 'Caracas', 'title' => 'Apaga CRE LOSER', 'steps' => ['Apágalo']],
        ['key' => '1:c1:cooldown', 'kind' => 'cooldown', 'city' => 'Caracas', 'title' => 'Espera', 'steps' => []]];
    $facts = [['product_id' => 1, 'stop_by_city' => ['Caracas' => ['CRE LOSER']], 'stop_all' => []]];
    DB::table('meta_analyses')->insert([
        ['date' => '2026-11-03', 'version' => 1, 'status' => 'ok', 'facts' => json_encode($facts), 'urgent' => json_encode($missions),
            'marks' => json_encode(['1:c1:scale' => ['status' => 'done', 'at' => '2026-11-03T08:00:00Z']])],
        ['date' => '2026-11-04', 'version' => 1, 'status' => 'ok', 'facts' => json_encode($facts), 'urgent' => json_encode([$missions[1]]),
            'marks' => json_encode(['1:stop:abc' => ['status' => 'disagree', 'at' => '2026-11-04T08:00:00Z']])],
    ]);
    DB::table('meta_changes')->insert(['fingerprint' => 'raise:c1:-:2026-11-03', 'kind' => 'raise', 'product_id' => 1, 'campaign_meta_id' => 'c1', 'city' => 'Caracas',
        'date' => '2026-11-03', 'changed_at' => '2026-11-03 09:00:00', 'mission' => json_encode(['date' => '2026-11-03', 'kind' => 'scale']), 'result' => 'met']);

    // Pedidos: sin IA 10 (6 entregados) y uno que esperó stock; con IA 4 maduros (3 entregados)
    $order = fn (int $id, string $date, string $outcome, int $status = 16) => ['order_id' => $id, 'product_id' => 1, 'city_id' => 1, 'kind' => 'normal',
        'created_date' => $date, 'outcome' => $outcome, 'status_id' => $status];
    DB::table('order_financials')->insert([...array_map(fn ($i) => $order($i, '2026-10-15', $i <= 6 ? 'delivered' : 'not_delivered'), range(1, 10)),
        $order(11, '2026-10-16', 'open', 3), ...array_map(fn ($i) => $order($i, '2026-11-05', $i <= 23 ? 'delivered' : 'not_delivered'), range(21, 24))]);

    // La ganancia sale de Finanzas (ProfitAndLoss, probado aparte): $10 por día sin IA, $20 con IA
    $pnl = Mockery::mock(ProfitAndLoss::class);
    $pnl->allows('report')->andReturnUsing(function ($from, $to) {
        $days = (int) $from->diffInDays($to) + 1;
        $perDay = $from->toDateString() < '2026-11-01' ? 10 : 20;

        return ['company' => ['operating_profit' => $perDay * $days, 'contribution' => $perDay * 2 * $days, 'revenue' => 100.0 * $days, 'advertising' => 20.0 * $days],
            'groups' => [], 'status' => ['label' => 'CONSOLIDADO']];
    });
    app()->instance(ProfitAndLoss::class, $pnl);

    $this->run = function (string $sections) {
        Artisan::call('meta:radiografia', ['--antes' => '2026-10-12:2026-10-25', '--despues' => '2026-11-02:', '--seccion' => $sections]);

        return Artisan::output();
    };
});

it('la ganancia por día y la efectividad: sin IA contra con IA (pedidos maduros, sin los que esperaron stock)', function () {
    $out = ($this->run)('negocio');

    expect($out)->toContain('sin IA 2026-10-12..2026-10-25 (14 d) · con IA 2026-11-02..2026-11-13 (12 d; pedidos maduros hasta 2026-11-09: 8 d)')
        ->toContain('ganancia neta (con gastos generales) | $10.00 | $20.00 | +100 %')
        ->toContain('efectividad (maduros, sin Sin Stock) | 60.0 % | 75.0 % | +25 %')
        // Meta: $20 por día con 2 compras → $30 con 4; con la vara del BE ($10): $0 → $10 por día
        ->toContain('CPA de Meta | $10.00 | $7.50 | −25 %')
        ->toContain('ganancia estimada de Meta (compras × BE − gasto) | $0.00 | $10.00 | —')
        ->toContain('pedidos que esperaron stock (fuera): sin IA 1 · con IA 0');
});

it('el dinero quemado: lo gastado en anuncios que perdían plata, y cuánto gastó un perdedor antes de apagarse', function () {
    $out = ($this->run)('quemado');

    expect($out)->toContain('sin IA: de $230 en anuncios, $20 en semanas que perdían plata (8.7 %)')
        ->toContain('perdedores que se apagaron: 1 · gastaron en promedio $20 (mediana $20) en 4 días')
        ->toContain('con IA: de $360 en anuncios, $72 en semanas que perdían plata (20 %) · $6/día');
});

it('la nota de cada misión: la que hizo y mejoró, y la que no hizo y la IA tenía razón (siguió perdiendo $7)', function () {
    $out = ($this->run)('misiones');

    expect($out)->toContain('scale | 1 | 1 | 0 | 0 | 1/0, $8.57 en promedio | 0/0/0, $0')
        ->toContain('stop_creatives | 1 | 0 | 1 | 0 | 0/0, — | 1/0/0, $7')
        ->toContain('2026-11-03 (2 d) | stop_creatives | FlexiStep | Caracas')
        ->toContain('no de acuerdo: la IA tenía razón (después: $42, 3.5 compras)')
        // esperar (nada que hacer en Ads Manager) no es una misión
        ->not->toContain('cooldown |');
});

it('el Historial de cambios: quién lo pidió y si se cumplió; la subida, con 7 días antes contra 7 después', function () {
    $out = ($this->run)('cambios');

    expect($out)->toContain('raise | 1 | 0 | 1 | 0 | 0')
        ->toContain('2026-11-03 | raise | FlexiStep Caracas | pedido: scale | gasto/día $21.43 → $30 | CPA $9.38 → $7.5 | gana/día $1.43 → $10 (7 d) | Historial: met');
});

it('solo lee: todo queda igual', function () {
    $tables = ['meta_analyses', 'meta_changes', 'meta_insights_daily', 'order_financials', 'settings', 'target_cpa_versions'];
    $count = fn () => array_map(fn ($t) => DB::table($t)->count(), $tables);
    $before = $count();

    ($this->run)('todo');

    expect($count())->toBe($before)->and(DB::transactionLevel())->toBe(0);
});
