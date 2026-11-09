<?php

namespace App\Services\Meta\Products;

/**
 * `.118` (dueño, 2026-11-09: "quiero que sea una misión, no un aviso, porque escalar debe ser una obligación, no una opción…
 * para desarrollar un producto nuevo hay que hacer ciertos pasos como buscar y analizar qué producto vamos a lanzar, luego hay
 * que crear la estrategia, luego hay que hacer los creativos, luego hacer la landing, y luego publicar en Meta"). PHP puro.
 * - Se abre cuando cada producto activo tiene su batch listo en la banca, ninguna ciudad necesita un batch hoy, no hay otra
 *   misión abierta, pasó 1 semana desde el último producto nuevo y el pronóstico dice que el equipo alcanza. Una vez abierta
 *   sigue hasta publicarse, aunque la banca cambie.
 * - 5 etapas en 7 días, su ritmo de 1 por semana. Si se vence un plazo suyo, va en rojo y corta su racha; los creativos
 *   dependen del equipo y no la cortan.
 */
final class NewProductRules
{
    /** Cuántos días entre un producto nuevo y el siguiente. */
    public const GAP_DAYS = 7;

    /** Las etapas, en orden. days: los días que tiene; manual: la marca el dueño; own: depende de él. */
    public const STAGES = [
        'search' => ['label' => 'Buscar y analizar el producto', 'who' => 'Tú', 'days' => 2, 'manual' => true, 'own' => true,
            'how' => 'Busca y analiza qué producto vas a lanzar. Cuando lo tengas elegido, márcalo.'],
        'strategy' => ['label' => 'Crear la estrategia', 'who' => 'Tú, con el asistente', 'days' => 1, 'manual' => false, 'own' => true,
            'how' => 'Dile a tu asistente «Producto nuevo: X», con su estrategia y el link de Shopify. Se marca sola al crear la ficha.'],
        'creatives' => ['label' => 'Hacer los creativos', 'who' => 'Líder y editores', 'days' => 2, 'manual' => false, 'own' => false,
            'how' => 'Al editor líder le llegan solos los 3 batches del testeo. Se marca sola cuando estén en la banca.'],
        'landing' => ['label' => 'Hacer la landing', 'who' => 'Tú', 'days' => 1, 'manual' => true, 'own' => true,
            'how' => 'Haz la landing del producto. Cuando esté lista, márcala.'],
        'publish' => ['label' => 'Publicar en Meta', 'who' => 'Tú', 'days' => 1, 'manual' => false, 'own' => true,
            'how' => 'Sigue la misión «Lanza el testeo» de Crecer. Se marca sola cuando Meta muestre los anuncios.'],
    ];

    /**
     * Lo que falta para abrir la misión (vacío: se abre).
     *
     * @param array{no_ready: list<string>, need_today: list<string>, last: ?string, today: string, forecast: ?string} $in forecast: el veredicto del pronóstico
     * @return list<string>
     */
    public static function missing(array $in): array
    {
        $out = [];
        if ($in['no_ready'] !== []) {
            $out[] = 'Falta un batch listo en la banca de ' . TestingRules::join($in['no_ready']) . '.';
        }
        if ($in['need_today'] !== []) {
            $out[] = 'Hoy necesitan un batch: ' . TestingRules::join($in['need_today']) . '.';
        }
        if ($in['last'] !== null && ($next = date('Y-m-d', strtotime($in['last'] . ' +' . self::GAP_DAYS . ' days'))) > $in['today']) {
            $out[] = 'El siguiente producto nuevo, a partir del ' . date('j/n', strtotime($next)) . '.';
        }
        if ($in['forecast'] !== 'ok') {
            $out[] = $in['forecast'] === null ? 'Todavía no hay pronóstico de los editores.' : 'Según el pronóstico, el equipo de editores no alcanza.';
        }

        return $out;
    }

    /**
     * Cada etapa con su fecha y cómo va. Las fechas se suman desde que se abrió.
     *
     * @param array<string, bool> $done etapa => terminada
     * @return list<array{key: string, label: string, who: string, how: string, manual: bool, own: bool, done: bool, due: string, late: bool}>
     */
    public static function stages(string $openedOn, array $done, string $today): array
    {
        $out = [];
        $days = 0;
        foreach (self::STAGES as $key => $s) {
            $days += $s['days'];
            $due = date('Y-m-d', strtotime("{$openedOn} +{$days} days"));
            $out[] = ['key' => $key, 'done' => (bool) ($done[$key] ?? false), 'due' => $due, 'late' => !($done[$key] ?? false) && $today > $due,
                'label' => $s['label'], 'who' => $s['who'], 'how' => $s['how'], 'manual' => $s['manual'], 'own' => $s['own']];
        }

        return $out;
    }

    /** La primera etapa suya que se pasó de la fecha (solo esa, para no repetir), o null. */
    public static function firstLate(array $stages): ?array
    {
        foreach ($stages as $s) {
            if ($s['late'] && $s['own']) {
                return $s;
            }
        }

        return null;
    }

    /** La misión de "vas tarde", para la lista del día. */
    public static function lateItem(int $id, ?int $productId, ?string $product, array $s): array
    {
        return ['key' => "new_product:{$id}:{$s['key']}", 'product_id' => (int) $productId, 'product' => $product ?? 'Producto nuevo', 'urgency' => null, 'city' => null,
            'kind' => 'new_product_late', 'label' => 'Producto nuevo', 'tone' => 'error', 'text' => "{$s['label']}: era para el " . date('j/n', strtotime($s['due'])) . '.',
            'where' => $product ?? 'Producto nuevo', 'title' => 'Vas tarde con el producto nuevo: ' . mb_strtolower($s['label']), 'steps' => [$s['how']],
            'why' => 'Era para el ' . date('j/n', strtotime($s['due'])) . ': escalar es una obligación', 'detail' => [], 'money' => null, 'severity' => 4, 'group' => 'crecer',
            'no_disagree' => true, 'no_done' => !$s['manual']];
    }
}
