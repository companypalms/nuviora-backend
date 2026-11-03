<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductProfile;
use App\Models\StoredFile;
use App\Services\Files\FileStore;
use App\Services\Files\Uploads;
use App\Services\Meta\AngleStats;
use App\Services\Meta\Products\ProductProfileRules;
use App\Services\Meta\Products\ProductProfiles;
use App\Services\Meta\Products\TestingPhase;
use App\Services\Meta\Products\TestingRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Configuración › Meta Ads › Ficha del producto (`.64`): qué es, para qué sirve, cómo funciona, lo que NO es, la
 * información clave, las fotos y los buyer personas de cada producto. También la llena el asistente con su tarjeta.
 */
class MetaProductProfileController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['product_id' => 'sometimes|nullable|integer|exists:products,id']);
        $products = self::products();
        $pid = isset($data['product_id']) ? (int) $data['product_id'] : ($products[0]['id'] ?? null);

        return response()->json(['products' => $products, 'product_id' => $pid] + ($pid ? self::present($pid) : []));
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'profile' => 'required|array',
            'profile.*' => 'nullable|string|max:' . ProductProfileRules::TEXT_MAX,
            'personas' => 'present|array|max:' . ProductProfileRules::MAX_PERSONAS,
            'personas.*.id' => 'sometimes|nullable|integer',
            'personas.*.name' => 'nullable|string|max:' . ProductProfileRules::NAME_MAX,
            'personas.*.who' => 'nullable|string|max:' . ProductProfileRules::TEXT_MAX,
            'personas.*.pain' => 'nullable|string|max:' . ProductProfileRules::TEXT_MAX,
            'personas.*.wants' => 'nullable|string|max:' . ProductProfileRules::TEXT_MAX,
            'personas.*.objections' => 'nullable|string|max:' . ProductProfileRules::TEXT_MAX,
            // .65: la fase y los ángulos (del más fuerte al más débil)
            'phase' => 'sometimes|in:' . implode(',', array_keys(TestingRules::PHASES)),
            'angles' => 'sometimes|array|max:' . TestingRules::MAX_ANGLES,
            'angles.*.code' => 'required|string|max:12',
            'angles.*.name' => 'nullable|string|max:120',
            'angles.*.hypothesis' => 'nullable|string|max:1000',
            'angles.*.persona' => 'nullable|string|max:' . ProductProfileRules::NAME_MAX,
        ]);
        $profile = ProductProfileRules::normalize($data['profile']);
        // Una fila vacía (todavía sin escribir) no es un buyer persona
        $personas = array_values(array_filter(array_map([ProductProfileRules::class, 'normalizePersona'], $data['personas']),
            fn ($p) => array_filter(array_intersect_key($p, ProductProfileRules::PERSONA_FIELDS)) !== []));
        $angles = array_key_exists('angles', $data) ? array_map([ProductProfileRules::class, 'normalizeAngle'], $data['angles']) : null;
        if ($problems = array_merge(ProductProfileRules::problems($profile, $personas), $angles !== null ? ProductProfileRules::angleProblems($angles, $personas) : [])) {
            throw ValidationException::withMessages(['profile' => $problems]);
        }
        DB::transaction(function () use ($product, $profile, $personas, $angles, $data) {
            ProductProfiles::save($product->id, $profile, $personas, Auth::id(), 'owner');
            if ($angles !== null) {
                TestingPhase::saveAngles($product->id, $angles, Auth::id());
            }
            if (isset($data['phase'])) {
                TestingPhase::setPhase($product->id, $data['phase']);
            }
        });

        return response()->json(['product_id' => $product->id] + self::present($product->id) + ['products' => self::products()]);
    }

    /**
     * `.90`: subir una foto del producto o su documento de estrategia directo al almacén (las fotos las ven los editores;
     * la estrategia, solo el dueño). Se confirma con "confirm".
     */
    public function upload(Request $request, Product $product, Uploads $uploads)
    {
        $data = $request->validate(['kind' => 'required|in:photo,strategy', 'name' => 'required|string|max:255', 'mime' => 'nullable|string|max:120',
            'size' => 'required|integer|min:1']);

        return response()->json($uploads->start(['kind' => $data['kind'], 'name' => $data['name'], 'product_id' => $product->id],
            (string) ($data['mime'] ?? ''), (int) $data['size'], $request->user()));
    }

    public function confirm(Request $request, StoredFile $file, Uploads $uploads)
    {
        abort_unless(in_array($file->kind, ['photo', 'strategy'], true) && $file->product_id, 404);
        $uploads->confirm($file, $request->user());

        return response()->json(['product_id' => $file->product_id, 'files' => self::files((int) $file->product_id)]);
    }

    /** Quitar una foto o un documento del producto (se borra del almacén). */
    public function forget(StoredFile $file)
    {
        abort_unless(in_array($file->kind, ['photo', 'strategy'], true) && $file->product_id, 404);
        $pid = (int) $file->product_id;
        Uploads::forget($file);

        return response()->json(['product_id' => $pid, 'files' => self::files($pid)]);
    }

    /** @return array{on: bool, photos: list<array>, strategy: list<array>} */
    public static function files(int $productId): array
    {
        $files = StoredFile::where('product_id', $productId)->whereIn('kind', ['photo', 'strategy'])->where('status', 'ready')->orderBy('id')->get();

        return [
            'on' => FileStore::on(),
            'photos' => $files->where('kind', 'photo')->map(fn (StoredFile $f) => $f->payload())->values()->all(),
            'strategy' => $files->where('kind', 'strategy')->map(fn (StoredFile $f) => $f->payload())->values()->all(),
        ];
    }

    /** .65: "Probar los siguientes": otra ronda de testeo con los ángulos de la ficha que no se probaron. */
    public function nextRound(Product $product)
    {
        TestingPhase::nextRound($product->id);

        return response()->json(['product_id' => $product->id] + self::present($product->id) + ['products' => self::products()]);
    }

    /** La ficha de un producto para la pantalla: lo guardado, lo que falta y quién la guardó. */
    public static function present(int $productId): array
    {
        $f = ProductProfiles::get($productId);
        $row = ProductProfile::with('updater:id,names')->where('product_id', $productId)->first();

        return [
            'profile' => $f['profile'] ?? array_fill_keys(array_keys(ProductProfileRules::FIELDS), null),
            'personas' => $f['personas'],
            'missing' => ProductProfileRules::missing($f['profile'], $f['personas']),
            'updated' => $row ? ['at' => $row->updated_at?->toIso8601String(), 'by' => $row->updater?->names, 'source' => $row->source] : null,
            'labels' => ['fields' => ProductProfileRules::FIELDS, 'persona' => ProductProfileRules::PERSONA_FIELDS],
            // .65: la fase, los ángulos de la ficha y en qué va el testeo
            'phase' => $row->phase ?? 'iterate', 'phases' => TestingRules::PHASES,
            'angles' => array_map(fn ($a) => ['code' => $a['code'], 'name' => $a['name'], 'hypothesis' => $a['hypothesis'], 'persona' => $a['persona']],
                TestingPhase::angles($productId)),
            'testing' => TestingPhase::summary($productId),
            'tested_angles' => TestingPhase::testedAngles($productId),
            // .90: las fotos (las ven los editores) y el documento de estrategia (solo el dueño), en el almacén
            'files' => self::files($productId),
        ];
    }

    /** Primero los productos con campañas (como en Ángulos); después los demás, por si uno nuevo todavía no tiene. */
    private static function products(): array
    {
        $withCampaigns = AngleStats::products();
        $ids = array_column($withCampaigns, 'id');
        $others = Product::whereNotIn('id', $ids ?: [0])->get(['id', 'title', 'showable_name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->showable_name ?: $p->title, 'campaigns' => 0, 'active_campaigns' => 0])
            ->sortBy('name')->values()->all();
        $all = array_merge($withCampaigns, $others);
        $complete = ProductProfiles::completeIds(array_column($all, 'id'));

        return array_map(fn ($p) => $p + ['complete' => in_array($p['id'], $complete, true)], $all);
    }
}
