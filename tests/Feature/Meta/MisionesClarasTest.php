<?php

use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\NameCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// .163 (dueño, 2026-11-19, LegUp en Caracas): "está diciendo unos gastos y unas cosas que no estoy viendo en Meta Ads en
// ninguno de los dos creativos que se llaman así". El cuadro gris era de toda la campaña, y el "C0181 CERV" del ad set
// B0128 ROD era en realidad el C0186 ROD. Con los números de producción de ese día (C $4, BE $5,50).

/** Un anuncio de AdFacts con sus números de 3 días y de toda su vida. */
function anuncio163(string $name, string $set, float $spend3, float $p3, float $spend, float $p, bool $active = true): array
{
    return ['name' => $name, 'campaign_meta_id' => 'ccs', 'adset_meta_id' => $set, 'active' => $active, 'creative_id' => null,
        'windows' => ['3d' => ['totals' => ['spend' => $spend3, 'purchases' => $p3]], 'max' => ['totals' => ['spend' => $spend, 'purchases' => $p]]]];
}

function legup163(): array
{
    return ['ads' => [
        'a1' => anuncio163('C0181 CERV', 's127', 3.91, 1, 12.92, 4),
        'a2' => anuncio163('C0181 CERV', 's128', 20.77, 3, 24.83, 3),
        'a3' => anuncio163('C0185 ROD', 's128', 9.10, 2, 15.00, 3),
        'a4' => anuncio163('C0189 LUMB', 's129', 6.00, 0, 9.00, 0, false),
    ], 'adsets' => ['s127' => ['name' => 'B0127 CERV'], 's128' => ['name' => 'B0128 ROD'], 's129' => ['name' => 'B0129 LUMB']]];
}

/** El veredicto del anuncio que se apaga, como lo deja AdRules::state (3 compras a $8,28: 50 % encima del BE). */
function perdedor163(): array
{
    return ['key' => 'loser', 'label' => 'Perdedor', 'reading' => 'Perdedor: llegó a 6,2×C con el CPA en el break-even o encima.',
        'why' => ['rule' => 'life_be', 'days' => null, 'spend' => 24.83, 'purchases' => 3.0, 'c' => 4.0, 'be' => 5.5, 'estimated' => false,
            'min' => 2.0, 'cap' => 3.0, 'margin' => 20.0, 'margin_n' => 20.0, 'watch' => 5]];
}

function intervencion163(array $pruneAds, array $prune): array
{
    $c = ['campaign_meta_id' => 'ccs', 'campaign' => 'LEGUP | CCS | CBO | 13/11/2026 CPA 4 BE 5.5', 'city' => 'Distrito Capital', 'notes' => [], 'budget_notes' => [], 'price_option' => null,
        'action' => ['key' => 'intervene', 'label' => 'Intervenir', 'tone' => 'error', 'data' => '', 'reading' => 'En el break-even o encima.', 'proposal' => null, 'from' => null, 'to' => null],
        'numbers' => ['spend3' => 68.43, 'purchases3' => 7, 'c' => 4.0, 'be' => 5.5, 'source' => 'name', 'budget' => 25.0, 'streak' => 1, 'prune' => $prune,
            'prune_ads' => $pruneAds, 'dates3' => ['2026-11-16', '2026-11-18'], 'live' => 3, 'offers' => [], 'gain3' => -29.93]];

    return ['product_id' => 9, 'product' => 'LegUp® Masajeador', 'mode' => 'normal', 'urgency' => ['level' => 2, 'label' => 'U2', 'batches' => '1', 'reasons' => []],
        'money_at_risk' => 0, 'next_work' => [], 'price' => [], 'price_watch' => null, 'alarms' => [], 'tracking' => null,
        'cities' => [$c], 'creatives' => [], 'batches' => []];
}

describe('.163: cada anuncio que se apaga, con sus números', function () {
    it('el anuncio guarda su ad set, sus números y los otros prendidos de la campaña que se llaman igual', function () {
        $f = legup163();
        $a = MetaDecisions::pruneAd($f, ['ads' => ['a2' => perdedor163()], 'creatives' => []], 'a2', 'ccs');

        expect($a)->toMatchArray(['name' => 'C0181 CERV', 'adset' => 'B0128 ROD', 'spend3' => 20.77, 'purchases3' => 3.0, 'spend' => 24.83, 'purchases' => 3.0,
            'rule' => 'ad', 'twins' => ['B0127 CERV']])
            ->and($a['why']['rule'])->toBe('life_be');
        // uno que no se repite no tiene gemelos; uno apagado no cuenta como gemelo
        expect(MetaDecisions::pruneAd($f, ['ads' => [], 'creatives' => []], 'a3', 'ccs')['twins'])->toBe([]);
    });

    it('la misión dice cuál de los dos apagar, cuál dejar, y separa los números del anuncio de los de la campaña', function () {
        $f = legup163();
        $ad = MetaDecisions::pruneAd($f, ['ads' => ['a2' => perdedor163()], 'creatives' => []], 'a2', 'ccs');
        $m = collect(UrgentList::build([intervencion163([$ad], ['C0181 CERV'])]))->firstWhere('kind', 'intervene');

        expect($m['title'])->toBe('Apaga 1 anuncio que pierde')
            ->and($m['steps'])->toBe(['Abre la campaña «LEGUP | CCS | CBO | 13/11/2026 CPA 4 BE 5.5».',
                'Apaga «C0181 CERV» del ad set «B0128 ROD» (gastó $20,77 en 3 días).',
                'No apagues el otro «C0181 CERV», el del ad set «B0127 CERV»: ese se queda prendido.',
                'No toques el presupuesto hoy.'])
            ->and($m['why'])->toBe('La campaña: $9,78 por compra; tu límite es $5,50 · 1 día encima')
            ->and(array_slice($m['detail'], 0, 4))->toBe([
                'Anuncio «C0181 CERV» del ad set «B0128 ROD»: en 3 días gastó $20,77 con 3 compras ($6,92 por compra); en toda su vida, $24,83 con 3 compras ($8,28 por compra).',
                'Tu C ahí es $4,00 y tu BE $5,50. Tu regla: con 2 compras o más se le da hasta 3 veces tu C; si llega con el costo por compra encima de tu BE (más de un 20 %, o con 20 compras o más), se apaga ahí.',
                'Ojo: hay otro «C0181 CERV» prendido en el ad set «B0127 CERV»: ese no se apaga.',
                'La campaña completa (todos sus anuncios), del 16 al 18/11: gastó $68,43 y Meta vio 7 compras = $9,78 por compra.',
            ])
            ->and($m['detail'])->toContain('En esos 3 días la campaña perdió unos $29,93 (compras × tu BE − gasto): unos $9,98 por día.');
    });

    it('si se apagan los dos que se llaman igual, cada uno con su ad set y sin "no apagues"', function () {
        $f = legup163();
        $out = ['ads' => ['a1' => perdedor163(), 'a2' => perdedor163()], 'creatives' => []];
        $ads = [MetaDecisions::pruneAd($f, $out, 'a1', 'ccs'), MetaDecisions::pruneAd($f, $out, 'a2', 'ccs')];
        $m = collect(UrgentList::build([intervencion163($ads, ['C0181 CERV', 'C0181 CERV'])]))->firstWhere('kind', 'intervene');

        expect($m['steps'][1])->toBe('Apaga «C0181 CERV» del ad set «B0127 CERV» (gastó $3,91 en 3 días) y «C0181 CERV» del ad set «B0128 ROD» (gastó $20,77 en 3 días).')
            ->and(collect($m['steps'])->filter(fn ($s) => str_starts_with($s, 'No apagues'))->all())->toBe([])
            ->and(collect($m['detail'])->filter(fn ($s) => str_starts_with($s, 'Ojo'))->all())->toBe([]);
    });

    it('un anuncio de 0 o 1 compra dice la regla del anuncio; sin nombre repetido, el paso es el de siempre', function () {
        $f = legup163();
        $v = ['key' => 'loser', 'reading' => 'Perdedor: gastó 2,3×C con una sola compra.', 'why' => ['rule' => 'life_spend', 'days' => null, 'spend' => 9.0, 'purchases' => 1.0,
            'c' => 4.0, 'be' => 5.5, 'estimated' => false, 'min' => 2.0, 'cap' => 3.0, 'margin' => 20.0, 'margin_n' => 20.0, 'watch' => 5]];
        $f['ads']['a3'] = anuncio163('C0185 ROD', 's128', 6.0, 1, 9.0, 1);
        $ad = MetaDecisions::pruneAd($f, ['ads' => ['a3' => $v], 'creatives' => []], 'a3', 'ccs');
        $m = collect(UrgentList::build([intervencion163([$ad], ['C0185 ROD'])]))->firstWhere('kind', 'intervene');

        expect($m['steps'][1])->toBe('Apaga «C0185 ROD».')
            ->and($m['detail'][0])->toBe('Anuncio «C0185 ROD» del ad set «B0128 ROD»: en 3 días gastó $6,00 con 1 compra; en toda su vida, $9,00 con 1 compra.')
            ->and($m['detail'][1])->toBe('Tu C ahí es $4,00 y tu BE $5,50. Tu regla: un anuncio que gasta 2 veces tu C con 0 o 1 compra se apaga.');
    });

    it('los análisis de antes (sin los números de cada anuncio) se ven como siempre', function () {
        $p = intervencion163([], ['C0181 CERV']);
        unset($p['cities'][0]['numbers']['prune_ads'], $p['cities'][0]['numbers']['dates3']);
        $m = collect(UrgentList::build([$p]))->firstWhere('kind', 'intervene');

        expect($m['steps'][1])->toBe('Apaga «C0181 CERV».')
            ->and($m['detail'][0])->toBe('La campaña completa (todos sus anuncios), en 3 días: gastó $68,43 y Meta vio 7 compras = $9,78 por compra.');
    });
});

/** Las piezas de los batches 127, 128 y 129 de LegUp, como las tiene el sistema. */
function piezas163(): array
{
    return [[181 => 127, 182 => 127, 183 => 127, 184 => 128, 185 => 128, 186 => 128, 187 => 129, 188 => 129, 189 => 129], [
        127 => ['adset' => 'B0127 CERV', 'pieces' => [181 => 'C0181 CERV', 182 => 'C0182 CERV', 183 => 'C0183 CERV']],
        128 => ['adset' => 'B0128 ROD', 'pieces' => [184 => 'C0184 ROD', 185 => 'C0185 ROD', 186 => 'C0186 ROD']],
        129 => ['adset' => 'B0129 LUMB', 'pieces' => [187 => 'C0187 LUMB', 188 => 'C0188 LUMB', 189 => 'C0189 LUMB']],
    ]];
}

function ad163(string $id, string $name, string $code, string $set, bool $active = true, string $campaign = 'ccs'): array
{
    return ['meta_id' => $id, 'name' => $name, 'campaign_meta_id' => $campaign, 'adset_meta_id' => $set, 'creative_code' => $code, 'active' => $active];
}

describe('.163: el nombre que no cuadra', function () {
    $sets = ['s127' => ['name' => 'B0127 CERV', 'batch_code' => 'B0127', 'campaign_meta_id' => 'ccs'], 's128' => ['name' => 'B0128 ROD', 'batch_code' => 'B0128', 'campaign_meta_id' => 'ccs'],
        's129' => ['name' => 'B0129 LUMB', 'batch_code' => 'B0129', 'campaign_meta_id' => 'ccs']];

    it('LegUp: el C0181 dentro de B0128 ROD es de otro batch, y como ahí falta solo el C0186, es ese', function () use ($sets) {
        [$pieces, $batches] = piezas163();
        $ads = [ad163('a1', 'C0181 CERV', 'C0181', 's127'), ad163('a2', 'C0182 CERV', 'C0182', 's127'), ad163('a3', 'C0181 CERV', 'C0181', 's128'),
            ad163('a4', 'C0184 ROD', 'C0184', 's128'), ad163('a5', 'C0185 ROD', 'C0185', 's128'), ad163('a6', 'C0187 LUMB', 'C0187', 's129'),
            ad163('a7', 'C0189 LUMB', 'C0189', 's129', false)];

        expect(NameCheck::wrong($ads, $sets, $pieces, $batches))->toBe(['ccs' => ['ads' => [['meta_id' => 'a3', 'name' => 'C0181 CERV', 'adset' => 'B0128 ROD', 'code' => 'C0181',
            'belongs' => 'B0127 CERV', 'suggest' => 'C0186 ROD', 'options' => ['C0186 ROD']]]]]);
    });

    it('sin una sola pieza que falte no adivina: da las que faltan; un anuncio apagado o con nombre viejo no cuenta', function () use ($sets) {
        [$pieces, $batches] = piezas163();
        $ads = [ad163('a3', 'C0181 CERV', 'C0181', 's128'), ad163('a4', 'C0184 ROD', 'C0184', 's128'),
            ad163('a8', 'C0182 CERV', 'C0182', 's129', false), ad163('a9', 'C005 FASP', 'C005', 's129'), ad163('a10', 'CRE001-FLEX-FAS-BATCH004', 'CRE001', 's129')];
        $w = NameCheck::wrong($ads, $sets, $pieces, $batches)['ccs']['ads'];

        expect($w)->toHaveCount(1)
            ->and($w[0]['suggest'])->toBeNull()
            ->and($w[0]['options'])->toBe(['C0185 ROD', 'C0186 ROD']);
    });

    it('todo bien nombrado: nada; el mismo creativo en otra ciudad tampoco', function () use ($sets) {
        [$pieces, $batches] = piezas163();
        $sets['x127'] = ['name' => 'B0127 CERV', 'batch_code' => 'B0127', 'campaign_meta_id' => 'mcy'];
        $ads = [ad163('a1', 'C0181 CERV', 'C0181', 's127'), ad163('a4', 'C0184 ROD', 'C0184', 's128'), ad163('b1', 'C0181 CERV', 'C0181', 'x127', true, 'mcy')];

        expect(NameCheck::wrong($ads, $sets, $pieces, $batches))->toBe([]);
    });

    it('un código C que no es de ninguna pieza y está en dos batches de la misma campaña se repite', function () use ($sets) {
        [$pieces, $batches] = piezas163();
        $ads = [ad163('a1', 'C0950 CERV', 'C0950', 's127'), ad163('a2', 'C0950 CERV - Copia', 'C0950', 's128')];

        expect(NameCheck::wrong($ads, $sets, $pieces, $batches))->toBe(['ccs' => ['repeats' => [['name' => 'C0950 CERV', 'adsets' => ['B0127 CERV', 'B0128 ROD']]]]]);
    });

    it('la misión "Corrige el nombre": qué nombre poner, dónde y que no toca nada más', function () {
        $w = ['campaign_meta_id' => 'ccs', 'campaign' => 'LEGUP | CCS | CBO | 13/11/2026 CPA 4 BE 5.5', 'product_id' => 9, 'product' => 'LegUp® Masajeador', 'city' => 'Distrito Capital',
            'ads' => [['meta_id' => 'a3', 'name' => 'C0181 CERV', 'adset' => 'B0128 ROD', 'code' => 'C0181', 'belongs' => 'B0127 CERV', 'suggest' => 'C0186 ROD', 'options' => ['C0186 ROD']]],
            'repeats' => []];
        $m = collect(UrgentList::build([], [], null, [], [], [$w]))->firstWhere('kind', 'rename');

        expect($m['title'])->toBe('Corrige el nombre: C0181 CERV de B0128 ROD es C0186 ROD')
            ->and($m['where'])->toBe('LegUp · Distrito Capital')
            ->and($m['group'])->toBe('arreglar')
            ->and($m['why'])->toBe('C0181 es del batch B0127 CERV: con ese nombre, sus resultados se suman a otro creativo')
            ->and($m['steps'])->toBe(['Abre la campaña «LEGUP | CCS | CBO | 13/11/2026 CPA 4 BE 5.5».',
                'En «Anuncios», busca «C0181 CERV» y elige el del ad set «B0128 ROD». Toca «Editar», cámbiale el nombre a «C0186 ROD» y publica.',
                'Cambiar solo el nombre no toca el video, el presupuesto ni lo que Meta ya aprendió del anuncio.'])
            ->and($m['detail'][0])->toBe('«C0181 CERV» está en el ad set «B0128 ROD», pero C0181 es una pieza del batch «B0127 CERV». En «B0128 ROD» falta «C0186 ROD».')
            ->and(UrgentList::tally([$m], [$m['key'] => ['status' => 'done']]))->toBe(['tasks' => 1, 'resolved' => 1])
            ->and(UrgentList::rename($w)['key'])->toBe($m['key']);
    });

    it('con varias piezas posibles, pide mirar el video; un código repetido, dejarlo solo en el que es', function () {
        $w = ['campaign_meta_id' => 'ccs', 'campaign' => 'LEGUP | CCS', 'product_id' => 9, 'product' => 'LegUp', 'city' => null,
            'ads' => [['meta_id' => 'a3', 'name' => 'C0181 CERV', 'adset' => 'B0128 ROD', 'code' => 'C0181', 'belongs' => 'B0127 CERV', 'suggest' => null, 'options' => ['C0185 ROD', 'C0186 ROD']]],
            'repeats' => [['name' => 'C0950 CERV', 'adsets' => ['B0127 CERV', 'B0128 ROD']]]];
        $m = UrgentList::rename($w);

        expect($m['title'])->toBe('Corrige el nombre de 2 anuncios')
            ->and($m['steps'][1])->toBe('En «Anuncios», busca «C0181 CERV» y elige el del ad set «B0128 ROD». Mira su video y ponle el nombre que le toca: «C0185 ROD» o «C0186 ROD». Publica.')
            ->and($m['steps'][2])->toBe('Busca «C0950 CERV»: está prendido en los ad sets «B0127 CERV» y «B0128 ROD». Mira sus videos: deja ese nombre solo en el que es y ponle al otro el código C de su pieza (está en Cola y banca).');
    });
});

describe('.163: de la base de datos', function () {
    beforeEach(function () {
        Schema::create('products', fn ($t) => [$t->id(), $t->string('title'), $t->string('showable_name')->nullable(), $t->timestamps()]);
        Schema::create('cities', fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()]);
        Schema::create('meta_ad_accounts', fn ($t) => [$t->id(), $t->boolean('is_active')->default(true), $t->timestamps()]);
        Schema::create('meta_campaigns', fn ($t) => [$t->id(), $t->string('meta_id'), $t->string('name'), $t->unsignedBigInteger('meta_ad_account_id'),
            $t->unsignedBigInteger('product_id')->nullable(), $t->unsignedBigInteger('city_id')->nullable(), $t->string('effective_status')->nullable(), $t->timestamp('missing_since')->nullable(), $t->timestamps()]);
        Schema::create('meta_adsets', fn ($t) => [$t->id(), $t->string('meta_id'), $t->string('campaign_meta_id'), $t->string('name'), $t->string('batch_code')->nullable(),
            $t->timestamp('missing_since')->nullable(), $t->timestamps()]);
        Schema::create('meta_ads', fn ($t) => [$t->id(), $t->string('meta_id'), $t->string('campaign_meta_id'), $t->string('adset_meta_id'), $t->string('name'),
            $t->string('creative_code')->nullable(), $t->string('effective_status')->nullable(), $t->timestamp('missing_since')->nullable(), $t->timestamps()]);
        Schema::create('creative_batches', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id'), $t->unsignedInteger('number')->nullable(), $t->string('status')->default('testing'),
            $t->string('angle_code')->nullable(), $t->timestamps()]);
        Schema::create('creative_batch_pieces', fn ($t) => [$t->id(), $t->unsignedBigInteger('creative_batch_id'), $t->unsignedInteger('number')->nullable(),
            $t->unsignedInteger('position')->nullable(), $t->timestamps()]);
        DB::table('products')->insert(['id' => 9, 'title' => 'LEGUP MASAJEADOR', 'showable_name' => 'LegUp']);
        DB::table('cities')->insert(['id' => 1, 'name' => 'Distrito Capital']);
        DB::table('meta_ad_accounts')->insert(['id' => 1, 'is_active' => true]);
        DB::table('meta_campaigns')->insert([
            ['meta_id' => '120001', 'name' => 'LEGUP | CCS | CBO', 'meta_ad_account_id' => 1, 'product_id' => 9, 'city_id' => 1, 'effective_status' => 'ACTIVE'],
            ['meta_id' => '120002', 'name' => 'LEGUP | MCAY | CBO', 'meta_ad_account_id' => 1, 'product_id' => 9, 'city_id' => null, 'effective_status' => 'PAUSED'],
        ]);
        foreach ([127 => 'CERV', 128 => 'ROD'] as $n => $angle) {
            $id = DB::table('creative_batches')->insertGetId(['product_id' => 9, 'number' => $n, 'angle_code' => $angle]);
            foreach (range(1, 3) as $i) {
                DB::table('creative_batch_pieces')->insert(['creative_batch_id' => $id, 'number' => ($n - 127) * 3 + 180 + $i, 'position' => $i]);
            }
        }
        DB::table('meta_adsets')->insert([['meta_id' => 's127', 'campaign_meta_id' => '120001', 'name' => 'B0127 CERV', 'batch_code' => 'B0127'],
            ['meta_id' => 's128', 'campaign_meta_id' => '120001', 'name' => 'B0128 ROD', 'batch_code' => 'B0128'],
            ['meta_id' => 'm128', 'campaign_meta_id' => '120002', 'name' => 'B0128 ROD', 'batch_code' => 'B0128']]);
        $ad = fn ($id, $c, $s, $name, $code, $status = 'ACTIVE') => ['meta_id' => $id, 'campaign_meta_id' => $c, 'adset_meta_id' => $s, 'name' => $name, 'creative_code' => $code, 'effective_status' => $status];
        DB::table('meta_ads')->insert([$ad('a1', '120001', 's127', 'C0181 CERV', 'C0181'), $ad('a3', '120001', 's128', 'C0181 CERV', 'C0181'),
            $ad('a4', '120001', 's128', 'C0184 ROD', 'C0184'), $ad('a5', '120001', 's128', 'C0185 ROD', 'C0185', 'PAUSED'),
            // en una campaña apagada no se mira
            $ad('m1', '120002', 'm128', 'C0181 CERV', 'C0181')]);
    });

    it('lee las campañas activas, los batches y sus piezas y arma lo de cada campaña', function () {
        expect(NameCheck::mismatches())->toBe([['campaign_meta_id' => '120001', 'campaign' => 'LEGUP | CCS | CBO', 'product_id' => 9, 'product' => 'LegUp', 'city' => 'Distrito Capital',
            'ads' => [['meta_id' => 'a3', 'name' => 'C0181 CERV', 'adset' => 'B0128 ROD', 'code' => 'C0181', 'belongs' => 'B0127 CERV', 'suggest' => 'C0186 ROD', 'options' => ['C0186 ROD']]],
            'repeats' => []]]);
    });

    it('cuando se corrige el nombre, no sale más', function () {
        DB::table('meta_ads')->where('meta_id', 'a3')->update(['name' => 'C0186 ROD', 'creative_code' => 'C0186']);

        expect(NameCheck::mismatches())->toBe([]);
    });
});
