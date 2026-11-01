<?php

namespace App\Services\Meta\Products;

use App\Models\ProductPersona;
use App\Models\ProductProfile;
use Illuminate\Support\Facades\DB;

/**
 * Leer y guardar la ficha de los productos (`.64`). La pantalla (Configuración › Meta Ads › Ficha del producto) y la
 * tarjeta del asistente guardan con lo mismo (save). Las reglas, en ProductProfileRules.
 */
class ProductProfiles
{
    /** @return array{profile: ?array, personas: list<array>} la ficha y sus buyer personas activos, en orden */
    public static function get(int $productId): array
    {
        return self::many([$productId])[$productId];
    }

    /** @return array<int, array{profile: ?array, personas: list<array>}> por producto (todos los pedidos, aunque no tengan ficha) */
    public static function many(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $profiles = ProductProfile::whereIn('product_id', $productIds)->get()->keyBy('product_id');
        $personas = ProductPersona::whereIn('product_id', $productIds)->whereNull('archived_at')->orderBy('position')->orderBy('id')->get()->groupBy('product_id');
        $out = [];
        foreach ($productIds as $pid) {
            $p = $profiles->get($pid);
            $out[$pid] = [
                'profile' => $p ? $p->only(array_keys(ProductProfileRules::FIELDS)) : null,
                'personas' => ($personas->get($pid) ?? collect())->map(fn (ProductPersona $x) => self::persona($x))->values()->all(),
            ];
        }

        return $out;
    }

    /** @return list<int> los productos con la ficha completa */
    public static function completeIds(array $productIds): array
    {
        return array_keys(array_filter(self::many($productIds), fn ($f) => ProductProfileRules::complete($f['profile'], $f['personas'])));
    }

    /** @return array<int, array> los buyer personas por id (también los archivados: un encargo viejo lo sigue mostrando) */
    public static function personasById(array $ids): array
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids))));

        return $ids === [] ? [] : ProductPersona::whereIn('id', $ids)->get()->mapWithKeys(fn (ProductPersona $x) => [$x->id => self::persona($x)])->all();
    }

    /**
     * Guarda la ficha completa. $personas es la lista que queda: los que traen id se actualizan, los nuevos se crean y
     * los activos que ya no están se archivan (nunca se borran).
     *
     * @param array $profile ProductProfileRules::normalize
     * @param list<array> $personas ProductProfileRules::normalizePersona
     */
    public static function save(int $productId, array $profile, array $personas, ?int $userId, string $source): void
    {
        DB::transaction(function () use ($productId, $profile, $personas, $userId, $source) {
            ProductProfile::updateOrCreate(['product_id' => $productId],
                array_intersect_key($profile, ProductProfileRules::FIELDS) + ['source' => $source, 'updated_by' => $userId]);
            $active = ProductPersona::where('product_id', $productId)->whereNull('archived_at')->lockForUpdate()->get()->keyBy('id');
            $keep = [];
            foreach (array_values($personas) as $i => $p) {
                $row = isset($p['id']) ? $active->get($p['id']) : null;
                $row ??= new ProductPersona(['product_id' => $productId]);
                $row->fill(array_intersect_key($p, ProductProfileRules::PERSONA_FIELDS) + ['position' => $i + 1])->save();
                $keep[] = $row->id;
            }
            $gone = $active->keys()->diff($keep)->values()->all();
            if ($gone !== []) {
                ProductPersona::whereIn('id', $gone)->update(['archived_at' => now()]);
            }
        });
    }

    private static function persona(ProductPersona $x): array
    {
        return ['id' => $x->id] + $x->only(array_keys(ProductProfileRules::PERSONA_FIELDS));
    }
}
