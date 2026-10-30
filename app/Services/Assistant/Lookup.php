<?php

namespace App\Services\Assistant;

use App\Models\City;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Metrics\Period;
use App\Services\Metrics\Readers\TeamReader;
use App\Services\Metrics\Team\TeamCatalog;

/**
 * Encuentra por nombre lo que nombra el dueño ("Fabiola", "la agencia Rápido", "Valencia"), sin importar mayúsculas ni
 * acentos. match() es PHP puro; lo demás trae las listas de la base (las mismas del Panel del dueño).
 */
class Lookup
{
    /** El rol en palabras del dueño => la clave del catálogo de Equipo. */
    public const ROLES = ['vendedoras' => 'sellers', 'lideres' => 'leaders', 'agencias' => 'agencies', 'repartidores' => 'riders', 'operadoras' => 'operators',
        'editores' => 'editors', 'editores_landing' => 'landing_editors'];

    public function __construct(private TeamReader $team)
    {
    }

    /** "José  Pérez" => "jose perez". */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);

        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text)));
    }

    /**
     * Los que se parecen al nombre buscado, del más parecido al menos: igual, empieza igual, tiene todas las palabras o
     * alguna palabra entera.
     *
     * @param array<int, string> $candidates id => nombre
     * @return array<int, array{id: int, name: string}>
     */
    public static function match(string $query, array $candidates, int $limit = 8): array
    {
        $q = self::normalize($query);
        if ($q === '') {
            return [];
        }
        $words = explode(' ', $q);
        $scored = [];
        foreach ($candidates as $id => $name) {
            $n = self::normalize((string) $name);
            $nameWords = explode(' ', $n);
            $score = match (true) {
                $n === $q => 100,
                str_starts_with($n, $q) => 80,
                array_diff($words, $nameWords) === [] => 70,
                count(array_filter($words, fn ($w) => str_contains($n, $w))) === count($words) => 50,
                array_intersect($words, $nameWords) !== [] => 30,
                default => 0,
            };
            if ($score > 0) {
                $scored[] = ['id' => (int) $id, 'name' => (string) $name, 'score' => $score];
            }
        }
        usort($scored, fn ($a, $b) => [$b['score'], $a['name']] <=> [$a['score'], $b['name']]);
        // Si uno es claramente el mejor (por ejemplo, el nombre exacto), solo ese
        if (count($scored) > 1 && $scored[0]['score'] >= 70 && $scored[1]['score'] < $scored[0]['score']) {
            $scored = [$scored[0]];
        }

        return array_map(fn ($s) => ['id' => $s['id'], 'name' => $s['name']], array_slice($scored, 0, $limit));
    }

    /**
     * Las personas de un rol del panel (para las líderes, sus grupos de venta) con lo que se ve debajo de su nombre.
     *
     * @return array<int, array{name: string, group: ?string}>
     */
    public function people(string $roleKey, Period $period): array
    {
        if ($roleKey === 'leaders') {
            $out = [];
            foreach ($this->team->salesGroups($period) as $id => $g) {
                $out[$id] = ['name' => $g['name'], 'group' => $g['leader'] ? "Líder: {$g['leader']}" : 'Sin líder'];
            }

            return $out;
        }
        $role = TeamCatalog::find($roleKey);

        return $role ? $this->team->people($role->systemRoles()) : [];
    }

    /**
     * Las personas de un rol que se parecen al nombre. En las líderes también se busca por el nombre de la líder.
     *
     * @return array<int, array{id: int, name: string, group: ?string}>
     */
    public function findPeople(string $roleKey, string $name, Period $period): array
    {
        $people = $this->people($roleKey, $period);
        $names = array_map(fn ($p) => $p['name'] . ($roleKey === 'leaders' && $p['group'] ? " ({$p['group']})" : ''), $people);

        return array_map(fn ($m) => ['id' => $m['id'], 'name' => $people[$m['id']]['name'], 'group' => $people[$m['id']]['group']], self::match($name, $names));
    }

    /** @throws ToolProblem si no hay ninguna o hay varias */
    public function shopId(?string $name): ?int
    {
        return $this->one('tienda', $name, fn () => Shop::orderBy('name')->pluck('name', 'id')->all());
    }

    /** @throws ToolProblem si no hay ninguna o hay varias */
    public function cityId(?string $name): ?int
    {
        return $this->one('ciudad', $name, fn () => City::orderBy('name')->pluck('name', 'id')->all());
    }

    /** @throws ToolProblem si no hay ninguno o hay varios */
    public function productId(?string $name): ?int
    {
        return $this->one('producto', $name, fn () => Product::orderBy('title')->get(['id', 'title', 'showable_name'])
            ->mapWithKeys(fn ($p) => [$p->id => $p->showable_name ?: $p->title])->all());
    }

    /** Las agencias, también las desactivadas (sus fechas viejas siguen contando). @throws ToolProblem si no hay ninguna o hay varias */
    public function agencyId(?string $name): ?int
    {
        return $this->one('agencia', $name, fn () => User::whereHas('role', fn ($q) => $q->where('description', 'Agencia'))
            ->orderBy('names')->pluck('names', 'id')->all());
    }

    private function one(string $what, ?string $name, callable $list): ?int
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        $found = self::match($name, $list());
        if ($found === []) {
            throw new ToolProblem('No encontré ' . ($what === 'producto' ? 'ningún' : 'ninguna') . " {$what} que se llame \"{$name}\".");
        }
        if (count($found) > 1) {
            throw new ToolProblem("Hay varias opciones para \"{$name}\": " . implode(', ', array_column($found, 'name')) . '. Pregúntale al dueño cuál.');
        }

        return $found[0]['id'];
    }
}
