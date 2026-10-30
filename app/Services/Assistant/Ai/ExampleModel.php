<?php

namespace App\Services\Assistant\Ai;

use App\Services\Assistant\Lookup;
use App\Services\Assistant\Turn;
use Carbon\CarbonImmutable;

/**
 * El modo ejemplo: en la PC de pruebas no hay clave de OpenAI (y nunca se usa la real). Entiende frases simples por
 * palabras ("ventas de la semana pasada", "efectividad de Fabiola", "ranking de agencias", "qué tengo hoy") y usa las
 * herramientas de verdad, así se prueba todo el chat con los datos de prueba sin gastar. No razona: muestra los datos
 * tal cual. Solo se usa sin clave y con APP_ENV=local. PHP puro.
 */
class ExampleModel implements AssistantModel
{
    public const NAME = 'ejemplo';

    private const INTRO = "**Modo ejemplo**: en esta PC no hay IA conectada, así que te muestro los datos tal cual. Con la IA conectada te los explicaría en palabras.";

    public function next(Turn $turn): ModelReply
    {
        $change = $turn->steps === [] ? self::change($turn->message, CarbonImmutable::now(), $turn->files !== []) : null;
        if ($turn->steps === [] && $turn->files !== [] && $change === null) {
            return self::say(self::files($turn->files));
        }
        if ($turn->steps === []) {
            $call = $change ?? self::understand($turn->message);
            if ($call === null) {
                return self::say(self::INTRO . "\n\nNo entendí la pregunta. Prueba con: \"¿Cuánto vendimos esta semana?\", \"Efectividad de Fabiola este mes\", \"Ranking de agencias del mes pasado\", \"¿Cómo voy con mis metas?\" o \"¿Qué tengo hoy?\".");
            }

            return new ModelReply(null, [['call_id' => 'ejemplo_1', 'name' => $call['name'], 'arguments' => $call['arguments']]], [], self::noUsage(), self::NAME);
        }

        $step = $turn->steps[array_key_last($turn->steps)];
        $data = json_decode((string) (array_values($step['outputs'])[0] ?? ''), true);

        return self::say(self::INTRO . "\n\n" . self::summary(is_array($data) ? $data : []));
    }

    /**
     * La herramienta y sus parámetros para una frase, o null si no se entiende. Público para probarlo.
     *
     * @return array{name: string, arguments: array}|null
     */
    public static function understand(string $message): ?array
    {
        $t = Lookup::normalize($message);
        $has = fn (string ...$words) => array_filter($words, fn ($w) => str_contains($t, $w)) !== [];
        $period = self::period($t);
        $role = match (true) {
            $has('agencia') => 'agencias',
            $has('repartidor') => 'repartidores',
            $has('lider', 'grupo') => 'lideres',
            $has('operadora') => 'operadoras',
            default => 'vendedoras',
        };
        $chart = $has('grafic');
        $panel = $period + ['tienda' => null, 'ciudad' => null, 'producto' => null];

        $name = self::name($message);
        // Paso 3: órdenes, inventario, comisiones, cierres, caja, gastos, tasas, usuarios, agencias y ciudades
        if ($has('orden') && preg_match('/#?\s?([a-z]{0,6}-?\d{3,})/i', $message, $m)) {
            return ['name' => 'historial_orden', 'arguments' => ['numero' => $m[1], 'id' => null]];
        }
        if ($has('se esta acabando', 'se acaba', 'stock bajo', 'se estan acabando')) {
            return ['name' => 'stock_bajo', 'arguments' => []];
        }
        if ($has('reabastec')) {
            return ['name' => 'reabastecimiento', 'arguments' => []];
        }
        if ($has('en camino', 'por recibir', 'compras')) {
            return ['name' => 'en_camino', 'arguments' => []];
        }
        if ($has('stock', 'inventario', 'cuanto queda', 'cuantas unidades')) {
            return ['name' => 'stock', 'arguments' => ['producto' => null, 'ciudad' => null]];
        }
        if ($has('comision')) {
            return ['name' => 'comisiones', 'arguments' => $period + ['grafico' => true]];
        }
        if ($has('cierre')) {
            return ['name' => 'cierres_agencias', 'arguments' => ['agencia' => null, 'estado' => null, 'semana' => null]];
        }
        if ($has('caja', 'saldo', 'cuentas')) {
            return ['name' => 'caja', 'arguments' => []];
        }
        if ($has('gasto')) {
            return ['name' => 'gastos', 'arguments' => $period + ['categoria' => null]];
        }
        if ($has('tasa')) {
            return ['name' => 'tasas', 'arguments' => []];
        }
        if ($has('usuario')) {
            return ['name' => 'usuarios', 'arguments' => ['rol' => null, 'nombre' => null]];
        }
        if ($has('ciudades', 'agencias trabajando', 'horario')) {
            return ['name' => 'agencias_ciudades', 'arguments' => ['agencia' => null, 'ciudad' => null]];
        }
        if ($has('calendario', 'pendiente', 'tengo hoy', 'que tengo', 'tareas')) {
            return ['name' => 'calendario', 'arguments' => ['cuando' => 'hoy', 'desde' => null, 'hasta' => null]];
        }
        if ($has('metas', 'meta del mes')) {
            return ['name' => 'metas', 'arguments' => []];
        }
        if ($has('alerta')) {
            return ['name' => 'alertas', 'arguments' => $panel];
        }
        if ($name !== null && $has('efectividad de', 'como va', 'numeros de', 'como le fue')) {
            return ['name' => 'persona', 'arguments' => ['rol' => $role, 'nombre' => $name, 'id' => null] + $panel];
        }
        if ($has('ranking', 'mejores', 'peores', 'quien vende')) {
            return ['name' => 'equipo', 'arguments' => ['rol' => $role] + $panel + ['grafico' => true]];
        }
        if ($has('ganancia', 'gane', 'ganamos', 'dinero', 'rentab', 'publicidad', 'cobrar')) {
            return ['name' => 'dinero', 'arguments' => $panel + ['grafico' => $chart]];
        }
        if ($has('embudo', 'confirma')) {
            return ['name' => 'embudo', 'arguments' => $panel + ['grafico' => $chart]];
        }
        if ($has('cancel', 'perdida', 'rechaz')) {
            return ['name' => 'perdidas', 'arguments' => $panel + ['grafico' => true]];
        }
        if ($has('venta', 'vendi', 'vendimos', 'pedido', 'efectividad')) {
            return ['name' => 'ventas', 'arguments' => $panel + ['grafico' => true]];
        }

        return null;
    }

    /**
     * Paso 5: frases simples que proponen un cambio (para probar las tarjetas sin IA). Público para probarlo.
     * - "cambia la tasa binance a 575"; "anota en el calendario pagar el alquiler mañana"; "marca como hecho revisar la tasa";
     * - "anota un gasto de 15 dólares de gasolina desde Banco DEMO" (con una foto, va de comprobante);
     * - "gasto fijo Hostinger de 12 dólares cada mes el día 3"; "pagué el alquiler desde Banco DEMO";
     * - "añade el banco Banesco en bolívares".
     *
     * @return array{name: string, arguments: array}|null
     */
    public static function change(string $message, CarbonImmutable $now, bool $hasFiles = false): ?array
    {
        // Sin acentos ni signos, pero con los decimales ("575,5" => "575.5")
        $t = str_replace('zzdeczz', '.', Lookup::normalize(preg_replace('/(\d)[.,](\d)/u', '$1zzdeczz$2', $message)));
        $amount = preg_match('/(\d+(?:[.,]\d+)?)\s*(dolares|dolar|usd|bolivares|bs|usdt)\b/u', $t, $m)
            ? ['monto' => (float) str_replace(',', '.', $m[1]), 'moneda' => match ($m[2]) { 'bolivares', 'bs' => 'VES', 'usdt' => 'USDT', default => 'USD' }]
            : null;
        $account = preg_match('/\b(?:desde|con)\s+(?:la cuenta\s+|el\s+)?(.+)$/u', $t, $m) ? trim($m[1]) : null;
        $date = match (true) {
            str_contains($t, 'pasado manana') => $now->addDays(2)->toDateString(),
            str_contains($t, 'manana') => $now->addDay()->toDateString(),
            default => $now->toDateString(),
        };

        if (preg_match('/\b(tasa|binance|bcv)\b.*?\b(?:a|en)\s+(\d+(?:[.,]\d+)?)/u', $t, $m) && preg_match('/cambi|pon|actualiz|esta en|subio|bajo/u', $t)) {
            $key = str_contains($t, 'binance') ? 'binance_usd' : (str_contains($t, 'euro') ? 'bcv_eur' : 'bcv_usd');

            return ['name' => 'proponer_tasas', 'arguments' => ['bcv_usd' => null, 'bcv_eur' => null, 'binance_usd' => null, $key => (float) str_replace(',', '.', $m[2])]];
        }
        if (preg_match('/^(?:marca(?:r)? como hech[oa]|ya (?:hice|revise|pague))\s+(.+)$/u', $t, $m)) {
            return ['name' => 'proponer_marca_calendario', 'arguments' => ['clave' => null, 'texto' => $m[1], 'fecha' => $now->toDateString(), 'estado' => 'hecho', 'nota' => null]];
        }
        if (preg_match('/gasto fijo\s+(.+?)\s+de\s+\d/u', $t, $m) && $amount && preg_match('/cada (semana|mes)/u', $t, $f)) {
            $day = preg_match('/dia\s+(\d{1,2})/u', $t, $d) ? (int) $d[1] : 1;

            return ['name' => 'proponer_gasto_fijo', 'arguments' => ['nombre' => ucfirst($m[1]), 'categoria' => 'Software', 'frecuencia' => $f[1] === 'semana' ? 'semanal' : 'mensual',
                'dia_de_pago' => $day, 'fecha_de_pago' => null, 'cada_dias' => null, 'tasa' => null, 'desde' => null] + $amount];
        }
        if (preg_match('/^(?:pague|paga|pagar)\s+(?:el gasto fijo\s+|el\s+|la\s+)?(.+?)(?:\s+(?:desde|con)\s+.+)?$/u', $t, $m)) {
            return ['name' => 'proponer_pago_gasto_fijo', 'arguments' => ['gasto_fijo' => $m[1], 'vencimiento' => null, 'pagado_el' => null, 'cuenta' => $account,
                'monto' => null, 'moneda' => null, 'tasa' => null, 'notas' => null, 'comprobante' => $hasFiles ? 1 : null]];
        }
        if (str_contains($t, 'gasto') && $amount && preg_match('/\bde\s+(?:\d+(?:[.,]\d+)?\s*\w+\s+)?de\s+([^\d]+?)(?:\s+(?:desde|con)\s+.+)?$/u', $t, $m)) {
            return ['name' => 'proponer_gasto', 'arguments' => ['concepto' => ucfirst(trim($m[1])), 'categoria' => 'Otros', 'tasa' => null, 'fecha' => null,
                'pago' => 'pagado', 'cuenta' => $account, 'a_quien_tipo' => null, 'a_quien' => null, 'vence' => null, 'descripcion' => null, 'comprobante' => $hasFiles ? 1 : null] + $amount];
        }
        if (preg_match('/^(?:anade|agrega|crea)\s+(?:el banco|la cuenta|un banco|una cuenta)\s+(.+?)(?:\s+en\s+(bolivares|dolares|usdt|euros))?$/u', $t, $m)) {
            $currency = match ($m[2] ?? '') { 'bolivares' => 'VES', 'usdt' => 'USDT', 'euros' => 'EUR', default => 'USD' };

            return ['name' => 'proponer_banco', 'arguments' => ['nombre' => ucwords($m[1]), 'tipo' => 'banco', 'moneda' => $currency, 'saldo_inicial' => null, 'notas' => null, 'cobro' => null]];
        }
        if (preg_match('/^(?:anota(?:me)?|recuerdame)\s+(?:en el calendario\s+)?(.+?)(?:\s+(?:pasado manana|manana|hoy))?$/u', $t, $m) && !str_contains($t, 'gasto')) {
            return ['name' => 'proponer_nota_calendario', 'arguments' => ['titulo' => ucfirst($m[1]), 'fecha' => $date, 'hora' => null,
                'tipo' => str_contains($t, 'pagar') ? 'pago' : 'tarea', 'repetir' => 'no', 'hasta' => null, 'detalles' => null]];
        }

        return null;
    }

    private static function period(string $t): array
    {
        $periodo = match (true) {
            str_contains($t, 'semana pasada') => 'semana_pasada',
            str_contains($t, 'esta semana') => 'esta_semana',
            str_contains($t, 'mes pasado') => 'mes_pasado',
            str_contains($t, 'ayer') => 'ayer',
            str_contains($t, 'hoy') => 'hoy',
            str_contains($t, '7 dias') || str_contains($t, 'ultimos 7') => 'ultimos_7_dias',
            default => 'este_mes',
        };

        return ['periodo' => $periodo, 'desde' => null, 'hasta' => null];
    }

    /** El nombre propio de la frase: "efectividad de Fabiola este mes" => "Fabiola". */
    private static function name(string $message): ?string
    {
        if (preg_match('/\b(?:de|va|a)\s+(?:la\s+|el\s+)?(?:agencia\s+|vendedora\s+|repartidor\s+)?(\p{Lu}[\p{L}]+(?:\s+\p{Lu}[\p{L}]+)*)/u', $message, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Qué archivos llegaron (sin IA no se pueden leer de verdad): el nombre, qué son y cómo empieza el texto. */
    public static function files(array $files): string
    {
        $lines = ["**Modo ejemplo**: recibí tus archivos, pero en esta PC no hay IA para leerlos. Esto es lo que llegó:"];
        foreach ($files as $file) {
            $line = "- **{$file['name']}**: {$file['label']}.";
            if (($file['type'] ?? null) === 'text') {
                // El texto viene entre <archivo …> y </archivo>: las primeras líneas de adentro
                $first = array_values(array_filter(array_slice(explode("\n", (string) $file['text']), 1, 4), fn ($l) => trim($l) !== '' && !str_starts_with(trim($l), '</archivo')));
                if ($first !== [] && str_starts_with($first[0], 'No se pudo leer: ')) {
                    $line .= ' ' . $first[0];
                } elseif ($first !== []) {
                    $line .= ' Empieza así: ' . implode(' / ', array_map(fn ($l) => mb_strimwidth(trim($l), 0, 120, '…'), array_slice($first, 0, 3)));
                }
            }
            $lines[] = $line;
        }
        $lines[] = "\nCon la IA conectada (en el sistema real) te diría qué dicen y contestaría tu pregunta.";

        return implode("\n", $lines);
    }

    /** Los datos de la herramienta, tal cual, en una lista corta. */
    private static function summary(array $d): string
    {
        $lines = [];
        if (isset($d['problema'])) {
            return $d['problema'];
        }
        if (isset($d['propuesta'])) {
            return "Te dejé la tarjeta abajo: revísala y toca **Confirmar** si está bien (o **Cancelar**)."
                . (isset($d['avisos']) ? "\n\nOjo: " . implode(' ', $d['avisos']) : '');
        }
        if (isset($d['varias_personas'])) {
            return "Hay varias personas con ese nombre:\n" . implode("\n", array_map(fn ($p) => "- {$p['nombre']}" . (isset($p['grupo']) ? " ({$p['grupo']})" : ''), $d['varias_personas']));
        }
        if (isset($d['periodo']['nombre'])) {
            $p = $d['periodo'];
            $lines[] = "**{$p['nombre']}**" . (str_starts_with($p['nombre'], 'Del ') ? '' : " (del {$p['desde']} al {$p['hasta']})") . (isset($d['persona']) ? " · **{$d['persona']}**" : '') . (isset($d['equipo']) ? " · {$d['equipo']}" : '');
        }
        if (isset($d['mes'])) {
            $lines[] = "Metas de **{$d['mes']}**" . (empty($d['metas_puestas']) ? ': todavía no las pusiste.' : '');
        }
        if (isset($d['resumen'])) {
            $lines[] = $d['resumen'];
        }
        foreach (array_slice($d['tarjetas'] ?? [], 0, 6) as $c) {
            $lines[] = "- {$c['dato']}: **{$c['valor']}**" . (isset($c['antes']) ? " (antes {$c['antes']}" . (isset($c['cambio']) ? ", {$c['cambio']}" : '') . ')' : '');
        }
        foreach (array_slice($d['personas'] ?? [], 0, 5) as $i => $p) {
            $first = array_values(array_diff_key($p, array_flip(['nombre', 'grupo', 'pocos_datos'])))[0] ?? '';
            $lines[] = ($i + 1) . ". {$p['nombre']}: {$first}" . (!empty($p['pocos_datos']) ? ' (pocos datos)' : '');
        }
        foreach (array_slice($d['lo_de_hoy'] ?? [], 0, 6) as $item) {
            $lines[] = "- {$item}";
        }
        foreach (array_slice($d['atrasado'] ?? [], 0, 4) as $item) {
            $lines[] = "- Atrasado: {$item}";
        }
        if (isset($d['alertas'])) {
            $lines[] = is_array($d['alertas']) ? count($d['alertas']) . ' alertas.' : $d['alertas'];
        }
        // Lo demás, tal cual: cada dato en una línea y cada lista con sus primeros elementos
        $done = ['periodo', 'persona', 'equipo', 'mes', 'metas_puestas', 'resumen', 'tarjetas', 'personas', 'lo_de_hoy', 'atrasado', 'alertas', 'hoy', 'aviso'];
        foreach (array_diff_key($d, array_flip($done)) as $key => $value) {
            $label = ucfirst(str_replace('_', ' ', (string) $key));
            if (is_scalar($value)) {
                $lines[] = "{$label}: **{$value}**";
            } elseif (is_array($value) && array_is_list($value)) {
                $lines[] = "**{$label}**";
                foreach (array_slice($value, 0, 8) as $item) {
                    $lines[] = '- ' . (is_array($item) ? self::flat($item) : $item);
                }
            } elseif (is_array($value)) {
                $lines[] = "**{$label}**: " . self::flat($value);
            }
        }

        return $lines !== [] ? implode("\n", $lines) : 'No encontré datos para eso.';
    }

    /** "estado: Pagado · carreras: 20". */
    private static function flat(array $item): string
    {
        $parts = [];
        foreach ($item as $k => $v) {
            $v = is_array($v) ? (array_is_list($v) ? implode(', ', array_map(fn ($x) => is_array($x) ? self::flat($x) : $x, $v)) : self::flat($v)) : $v;
            $parts[] = is_int($k) ? $v : str_replace('_', ' ', (string) $k) . ": {$v}";
        }

        return implode(' · ', $parts);
    }

    private static function say(string $text): ModelReply
    {
        return new ModelReply($text, [], [], self::noUsage(), self::NAME);
    }

    private static function noUsage(): array
    {
        return ['input' => 0, 'cached' => 0, 'output' => 0];
    }
}
