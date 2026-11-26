<?php

namespace App\Services\Meta\Products;

use App\Models\CreativeBatch;
use App\Models\NewProductMission;
use App\Models\ProductProfile;
use App\Models\StoredFile;
use App\Services\Meta\AngleStats;
use App\Services\Meta\Decisions\DecisionSettings;
use Illuminate\Support\Facades\DB;

/**
 * `.180` (dueño, 2026-11-26: "necesito que al líder le aparezca de manera fácil e intuitiva la ficha de los productos y el
 * pdf e imágenes que yo monto para cada producto"): la pantalla "Productos" del editor líder. `.177` mostraba solo la cola,
 * y un producto nuevo sale de la cola apenas el equipo tiene chance: con él se iban la ficha, las fotos y la estrategia.
 * - Todos los productos con los que trabaja el equipo: los de la cola del dueño (en su orden, con "Adelantar"), los que
 *   están en testeo y los que venden (con campañas activas). Los que el dueño paró no salen (en la cola sí, con lo que les
 *   falta).
 * - De cada uno, su hoja: la ficha sin precios, los buyer personas, los ángulos, las fotos y los documentos de estrategia
 *   (el dueño eligió que el líder y los editores los vean).
 */
final class ProductCatalog
{
    public static function forLeader(): array
    {
        $queue = ProductQueue::rows();
        $queued = array_column($queue, 'product_id');
        $stopped = ProductModes::stoppedIds();
        $testing = array_values(array_diff(ProductProfile::where('phase', 'testing')->orderByDesc('phase_at')->pluck('product_id')->map(fn ($v) => (int) $v)->all(),
            $queued, $stopped));
        $active = collect(AngleStats::products())->filter(fn ($p) => $p['active_campaigns'] > 0)->keyBy('id');
        $selling = array_values(array_diff($active->keys()->map(fn ($v) => (int) $v)->all(), $queued, $testing, $stopped));
        $names = DB::table('products')->whereIn('id', array_merge($testing, $selling) ?: [0])->get(['id', 'title', 'showable_name'])
            ->mapWithKeys(fn ($p) => [(int) $p->id => (string) ($p->showable_name ?: $p->title)]);
        $sheets = self::sheets(array_merge($queued, $testing, $selling));
        $mission = NewProductMission::where('status', 'open')->value('product_id');
        $next = ProductQueueRules::pick($queue, $mission !== null ? (int) $mission : null);
        // los mismos que pide "Adelantar" (EditorTeam::advance)
        $batches = max(1, (int) (new DecisionSettings())->get()['start_batches']);

        $out = [];
        foreach ($queue as $r) {
            $out[] = ['product_id' => $r['product_id'], 'product' => $r['product'], 'stage' => 'queued', 'note' => "Lugar {$r['position']} de la cola",
                'position' => $r['position'], 'ready' => $r['missing'] === [], 'waiting_for' => $r['leader_note'], 'next' => $r['product_id'] === $next,
                'batches' => $batches] + $sheets[$r['product_id']];
        }
        foreach ($testing as $pid) {
            $out[] = ['product_id' => $pid, 'product' => $names[$pid] ?? "Producto {$pid}", 'stage' => 'testing', 'note' => self::testingNote($pid)] + $sheets[$pid];
        }
        foreach ($selling as $pid) {
            $n = (int) $active[$pid]['active_campaigns'];
            $out[] = ['product_id' => $pid, 'product' => $names[$pid] ?? "Producto {$pid}", 'stage' => 'selling',
                'note' => $n === 1 ? '1 campaña activa' : "{$n} campañas activas"] + $sheets[$pid];
        }

        return ['products' => $out, 'building' => ProductQueue::workshop()['building']];
    }

    /**
     * La hoja de cada producto, como la ve el equipo: la ficha sin precios, todos los buyer personas, los ángulos (de qué
     * trata cada uno, sin el orden de fuerza), las fotos y los documentos de estrategia.
     *
     * @return array<int, array{info: ?array, personas: list<array>, angles: list<array>, photos: list<array>, strategy: list<array>}>
     */
    public static function sheets(array $ids): array
    {
        $fichas = ProductProfiles::many($ids);
        $angles = TestingPhase::anglesMany($ids);
        $photos = StoredFile::filesOf($ids, 'photo');
        $docs = StoredFile::filesOf($ids, 'strategy');
        $out = [];
        foreach ($ids as $pid) {
            $out[$pid] = [
                'info' => ProductProfileRules::forEditor($fichas[$pid]['profile'] ?? null),
                'personas' => ProductProfileRules::personasForEditor($fichas[$pid]['personas'] ?? []),
                'angles' => ProductProfileRules::anglesForEditor($angles[$pid] ?? []),
                'photos' => $photos[$pid] ?? [], 'strategy' => $docs[$pid] ?? [],
            ];
        }

        return $out;
    }

    /** En qué va su testeo: "Testeo: 1 de 3 en la banca" o "Testeo en Meta: 3 de 3". */
    private static function testingNote(int $productId): string
    {
        $st = TestingPhase::round($productId);
        $ids = array_values(array_filter(array_column($st['slots'], 'batch_id')));

        return ProductQueueRules::testingNote($ids === [] ? [] : CreativeBatch::whereIn('id', $ids)->pluck('status')->all(), count($st['slots']));
    }
}
