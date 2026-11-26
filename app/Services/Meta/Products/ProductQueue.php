<?php

namespace App\Services\Meta\Products;

use App\Models\CreativeBatch;
use App\Models\NewProductMission;
use App\Models\ProductAdSetting;
use App\Models\ProductProfile;
use App\Models\Setting;
use App\Models\StoredFile;
use App\Models\User;
use App\Notifications\MetaWorkNotification;
use App\Services\Meta\Queue\DemandRules;
use App\Services\Meta\Queue\LateBatches;
use App\Services\Meta\Queue\QueueProposer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `.154`: la cola de productos por montar, con la base de datos. Las reglas, en ProductQueueRules.
 * - Un producto en la cola tiene la fase "queued" y su lugar (queue_position, 1 = el siguiente).
 * - La revisión (release) va con cada corrida de QueueProposer: cada madrugada con el análisis, cuando entra a la banca un
 *   batch del testeo y cuando el dueño agrega uno a la cola. Si hay chance, el siguiente pasa a "En testeo" (sus batches se
 *   piden en esa misma corrida) y se le avisa al dueño en la campanita.
 */
class ProductQueue
{
    /** Lo que faltaba en la última revisión (fecha, hora y lista, sin el "el equipo está haciendo…": eso se ve en vivo). */
    public const CHECK = 'meta_product_queue_check';

    /** @return list<int> los productos de la cola, en orden */
    public static function ids(): array
    {
        return ProductProfile::where('phase', 'queued')->orderByRaw('queue_position is null')->orderBy('queue_position')->orderBy('phase_at')->orderBy('id')
            ->pluck('product_id')->map(fn ($v) => (int) $v)->all();
    }

    /** El lugar de uno que entra a la cola: al final. */
    public static function nextPosition(): int
    {
        return (int) ProductProfile::where('phase', 'queued')->max('queue_position') + 1;
    }

    /**
     * Los de la cola, en orden, con lo que le falta a cada uno para poder salir.
     *
     * @return list<array{product_id: int, product: string, position: int, missing: list<string>, leader_note: ?string, linked: bool, angles: int}>
     */
    public static function rows(): array
    {
        $ids = self::ids();
        if ($ids === []) {
            return [];
        }
        $products = DB::table('products')->whereIn('id', $ids)->get(['id', 'title', 'showable_name', 'product_id'])->keyBy('id');
        $fichas = ProductProfiles::many($ids);
        $angles = TestingPhase::anglesMany($ids);
        $modes = ProductAdSetting::whereIn('product_id', $ids)->pluck('mode', 'product_id');
        $out = [];
        foreach ($ids as $i => $pid) {
            $p = $products->get($pid);
            $ficha = ProductProfileRules::missing($fichas[$pid]['profile'], $fichas[$pid]['personas']);
            $mode = (string) ($modes[$pid] ?? 'normal');
            $out[] = ['product_id' => $pid, 'product' => (string) (($p->showable_name ?? null) ?: ($p->title ?? "Producto {$pid}")), 'position' => $i + 1,
                'missing' => ProductQueueRules::notReady($ficha, $angles[$pid] !== [], $mode),
                // .177: lo mismo, dicho para el editor líder
                'leader_note' => ProductQueueRules::forLeader($ficha, $angles[$pid] !== [], $mode),
                'linked' => (int) ($p->product_id ?? 0) > 0, 'angles' => count($angles[$pid])];
        }

        return $out;
    }

    /**
     * Los productos en testeo y en qué van sus batches de la ronda. "En el taller": el equipo todavía no entregó todos (solo
     * los activos con la ficha completa: sin ella no se piden). "Listo para lanzar": todos en la banca y ninguno salió.
     *
     * @return array{building: list<array{product_id: int, product: string, delivered: int, of: int}>, ready: list<array{product_id: int, product: string, of: int}>}
     */
    public static function workshop(): array
    {
        $profiles = ProductProfile::where('phase', 'testing')->where('testing_round', '>', 0)->orderByRaw('released_at is null')->orderBy('released_at')->orderBy('phase_at')->get(['product_id']);
        $ids = $profiles->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        if ($ids === []) {
            return ['building' => [], 'ready' => []];
        }
        $names = DB::table('products')->whereIn('id', $ids)->get(['id', 'title', 'showable_name'])->keyBy('id');
        $stopped = ProductModes::stoppedIds();
        $complete = ProductProfiles::completeIds($ids);
        $building = $ready = [];
        foreach ($ids as $pid) {
            $st = TestingPhase::round($pid);
            if ($st['closed'] !== null || $st['slots'] === [] || in_array($pid, $stopped, true)) {
                continue;
            }
            $batchIds = array_values(array_filter(array_column($st['slots'], 'batch_id')));
            $statuses = $batchIds === [] ? [] : CreativeBatch::whereIn('id', $batchIds)->pluck('status')->all();
            $prog = ProductQueueRules::progress($statuses, count($st['slots']));
            $name = (string) (($names[$pid]->showable_name ?? null) ?: ($names[$pid]->title ?? "Producto {$pid}"));
            if (!$prog['done'] && in_array($pid, $complete, true)) {
                $building[] = ['product_id' => $pid, 'product' => $name, 'delivered' => $prog['delivered'], 'of' => $prog['of']];
            } elseif ($prog['done'] && count($statuses) === $prog['of'] && !array_diff($statuses, ['bank'])) {
                $ready[] = ['product_id' => $pid, 'product' => $name, 'of' => $prog['of']];
            }
        }

        return ['building' => $building, 'ready' => $ready];
    }

    /**
     * La revisión: si hay chance (nada urgente, nada atrasado y nada en el taller), sale el siguiente de la cola.
     *
     * @param array<int, array> $decisions MetaDecisions (los productos activos en Meta)
     * @return ?int el producto que salió
     */
    public function release(array $decisions, string $today, int $correctionDays): ?int
    {
        if (!ProductProfile::where('phase', 'queued')->exists()) {
            return null;
        }
        $rows = self::rows();
        $testing = ProductProfile::where('phase', 'testing')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $needToday = [];
        foreach ($decisions as $pid => $d) {
            if (($d['product']['mode'] ?? 'normal') !== 'normal' || in_array((int) $pid, $testing, true)) {
                continue;
            }
            $t = DemandRules::today($d);
            foreach (array_keys($t['today'] > 0 ? $t['cities'] : []) as $city) {
                $needToday[] = "{$d['product']['name']} en {$city}";
            }
        }
        $late = count(LateBatches::find($decisions, $today, $correctionDays));
        $building = self::workshop()['building'][0] ?? null;
        $mission = NewProductMission::where('status', 'open')->value('product_id');
        $next = ProductQueueRules::pick($rows, $mission !== null ? (int) $mission : null);
        $in = ['need_today' => $needToday, 'late' => $late, 'building' => $building, 'next' => $next, 'waiting' => count($rows)];
        $released = null;
        if (ProductQueueRules::missing($in) === [] && $next !== null) {
            $released = self::start($next);
            $in['waiting'] = count($rows) - 1;
            $in['next'] = ProductQueueRules::pick(array_values(array_filter($rows, fn ($r) => $r['product_id'] !== $next)), null);
        }
        Setting::set(self::CHECK, ['date' => $today, 'at' => now()->toIso8601String(), 'missing' => ProductQueueRules::missing(['building' => null] + $in)]);

        return $released;
    }

    /** Lo que muestra la pantalla "Productos por montar". */
    public static function view(): array
    {
        $rows = self::rows();
        $ids = array_column($rows, 'product_id');
        $photos = StoredFile::photosOf($ids);
        $docs = StoredFile::whereIn('product_id', $ids ?: [0])->where('kind', 'strategy')->where('status', 'ready')->groupBy('product_id')
            ->selectRaw('product_id, count(*) as n')->pluck('n', 'product_id');
        foreach ($rows as &$r) {
            $r['photos'] = count($photos[$r['product_id']] ?? []);
            $r['photo_url'] = $photos[$r['product_id']][0]['url'] ?? null;
            $r['strategy'] = (int) ($docs[$r['product_id']] ?? 0);
        }
        unset($r);
        $check = (array) Setting::get(self::CHECK, []);
        $mission = NewProductMission::where('status', 'open')->value('product_id');

        return ['queue' => $rows] + self::workshop() + [
            'missing' => array_values($check['missing'] ?? []), 'checked_at' => $check['at'] ?? null,
            'next' => ProductQueueRules::pick($rows, $mission !== null ? (int) $mission : null),
            'mission_product_id' => $mission !== null ? (int) $mission : null,
        ];
    }

    /** Subir o bajar uno en la cola. */
    public static function move(int $productId, string $dir): void
    {
        $ids = self::ids();
        if (!in_array($productId, $ids, true)) {
            throw ValidationException::withMessages(['product' => 'Ese producto ya no está en la cola.']);
        }
        self::renumber(ProductQueueRules::move($ids, $productId, $dir));
    }

    /** "Sacar de la cola": vuelve a no tener fase de testeo ("Iterar lo que funciona", como un producto sin testear). */
    public static function remove(int $productId): void
    {
        if (!in_array($productId, self::ids(), true)) {
            throw ValidationException::withMessages(['product' => 'Ese producto ya no está en la cola.']);
        }
        TestingPhase::setPhase($productId, 'iterate');
    }

    /** Uno salió de la cola (a mano, por la ficha o por el asistente): la cola se vuelve a numerar y, si no fue a testeo, deja la misión. */
    public static function left(int $productId, string $to): void
    {
        self::renumber(self::ids());
        if ($to !== 'testing') {
            NewProductMission::where('status', 'open')->where('product_id', $productId)->whereNull('publish_done_at')
                ->update(['product_id' => null, 'strategy_done_at' => null, 'creatives_done_at' => null]);
        }
    }

    /** Un batch del testeo entró a la banca: si hay alguno esperando en la cola, se revisa (puede salir el siguiente). */
    public static function wake(): void
    {
        if (ProductProfile::where('phase', 'queued')->exists()) {
            QueueProposer::later();
        }
    }

    /** @param list<int> $ids en el orden nuevo */
    private static function renumber(array $ids): void
    {
        foreach (array_values($ids) as $i => $pid) {
            ProductProfile::where('product_id', $pid)->where('phase', 'queued')->update(['queue_position' => $i + 1]);
        }
    }

    /**
     * `.175`: el editor líder lo adelanta desde "Para adelantar": sale ya, aunque el equipo no tenga chance. Solo uno que está en
     * la cola y no le falta nada.
     */
    public static function startNow(int $productId): int
    {
        $row = collect(self::rows())->firstWhere('product_id', $productId);
        if ($row === null || $row['missing'] !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['product_id' => 'Ese producto ya no está en la cola o le falta algo: vuelve a abrir la pantalla.']);
        }

        return self::start($productId);
    }

    /** Sale de la cola: empieza su testeo (los batches los pide la misma corrida) y se le avisa al dueño. */
    private static function start(int $productId): int
    {
        $p = ProductProfile::where('product_id', $productId)->firstOrFail();
        // .179: si su ronda sigue abierta (con batches ya pedidos), sale con la misma ronda: sus batches siguen siendo su testeo
        $round = TestingPhase::openRound($productId) !== [] ? $p->testing_round : $p->testing_round + 1;
        $p->forceFill(['phase' => 'testing', 'phase_at' => now(), 'testing_round' => $round, 'released_at' => now(), 'queue_position' => null])->save();
        self::renumber(self::ids());
        $name = DB::table('products')->where('id', $productId)->get(['title', 'showable_name'])->map(fn ($x) => $x->showable_name ?: $x->title)->first() ?? 'El producto';
        try {
            foreach (User::whereNull('deactivated_at')->whereHas('role', fn ($q) => $q->whereIn('description', ['Admin', 'Master']))->get() as $u) {
                $u->notify(new MetaWorkNotification('meta_ready', "Salió {$name} de tu cola de productos por montar: sus batches del testeo ya le llegan al editor líder.", '/meta-ads/por-montar'));
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $productId;
    }
}
