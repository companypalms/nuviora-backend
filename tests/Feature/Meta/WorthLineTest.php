<?php

use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\Rules\ProductWorth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// .147 (dueño, 2026-11-16: "debemos bajar la etiqueta del 45% al 40% igual que la de la primera lectura. y regular tambien
// debe ser igual que la primera lectura"): ResistBarPro salía Malo con 44 % en 75 pedidos, dejando ~$40 por día.

beforeEach(function () {
    Schema::dropIfExists('settings');
    Schema::create('settings', function ($t) {
        $t->id();
        $t->string('key')->unique();
        $t->text('value')->nullable();
        $t->timestamps();
    });
});

function guardarReglas(array $values): void
{
    DB::table('settings')->insert(['key' => DecisionSettings::KEY, 'value' => json_encode($values), 'created_at' => now(), 'updated_at' => now()]);
}

function reglasGuardadas(): array
{
    return json_decode((string) DB::table('settings')->where('key', DecisionSettings::KEY)->value('value'), true);
}

describe('.147: Malo debajo de 40 %, como en la primera lectura', function () {
    it('ResistBarPro (44 % en 75 pedidos) queda Regular; con 39 %, Malo; las dos lecturas tienen las mismas líneas', function () {
        $s = DecisionSettings::defaults();

        expect($s['worth_bad_pct'])->toBe($s['worth_early_bad_pct'])->toEqual(40)
            ->and(ProductWorth::verdict(['orders' => 75, 'delivered' => 33], null, $s))->toMatchArray(['level' => 'regular', 'early' => false, 'effectiveness' => 44.0])
            ->and(ProductWorth::verdict(['orders' => 75, 'delivered' => 29], null, $s)['level'])->toBe('bad')     // 38,7 %
            ->and(ProductWorth::verdict(['orders' => 40, 'delivered' => 17], null, $s)['level'])->toBe('regular'); // 42,5 %, primera lectura
    });

    it('las reglas guardadas pasan de 45 a 40 al publicar (y vuelven con el rollback); lo demás no se toca', function () {
        guardarReglas(['worth_bad_pct' => 45, 'worth_good_pct' => 55, 'decrease_pct' => 25]);
        $migration = require database_path('migrations/2026_11_16_100000_worth_bad_line_to_40.php');

        $migration->up();
        expect(reglasGuardadas())->toBe(['worth_bad_pct' => 40, 'worth_good_pct' => 55, 'decrease_pct' => 25])
            ->and((new DecisionSettings())->get()['worth_bad_pct'])->toEqual(40);

        $migration->down();
        expect(reglasGuardadas()['worth_bad_pct'])->toEqual(45);
    });

    it('un número que puso el dueño a mano (42) no se toca; sin reglas guardadas, vale el 40 inicial', function () {
        $migration = require database_path('migrations/2026_11_16_100000_worth_bad_line_to_40.php');
        $migration->up();
        expect(DB::table('settings')->count())->toBe(0)->and((new DecisionSettings())->get()['worth_bad_pct'])->toEqual(40);

        guardarReglas(['worth_bad_pct' => 42]);
        $migration->up();
        expect(reglasGuardadas()['worth_bad_pct'])->toEqual(42);
    });
});
