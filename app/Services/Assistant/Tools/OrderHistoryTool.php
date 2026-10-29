<?php

namespace App\Services\Assistant\Tools;

use App\Models\Order;
use App\Services\Assistant\OrderFinder;
use App\Services\Assistant\Present;
use App\Services\Metrics\Rules\LossRules;
use App\Services\Orders\OrderHistory;

/**
 * Qué pasó con una orden: sus datos y todo su historial en orden (la pestaña "Acciones" de la ficha), para que la IA lo
 * cuente. Los cambios de teléfono, dirección o ubicación no se le pasan (solo que cambiaron).
 */
class OrderHistoryTool implements Tool
{
    /** Los eventos que se ven en la pantalla, como mucho (los más recientes). */
    private const SCREEN_EVENTS = 40;

    /** Los datos del cliente que no van a la IA. */
    private const PRIVATE_FIELDS = '/\'(phone|location|location_lat|location_lng|location_note|address|address1|address2|email|courier_recipient_\w+)\'/i';

    public function __construct(private OrderFinder $finder, private OrderHistory $history)
    {
    }

    public function name(): string
    {
        return 'historial_orden';
    }

    public function description(): string
    {
        return 'Qué pasó con UNA orden: sus datos (estado, total, cliente, vendedora, agencia, repartidor, fechas, pagos, cancelación, novedad) y su historial completo en orden de tiempo, con quién hizo cada cosa. Úsala para "qué pasó con la orden X", "por qué se canceló", "quién la movió". Por número o por id (si ya lo tienes de buscar_orden).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'numero' => ['type' => ['string', 'null'], 'description' => 'El número de la orden ("9003", "#P9003").'],
                'id' => ['type' => ['integer', 'null'], 'description' => 'El id, si ya lo tienes de buscar_orden.'],
            ],
            'required' => ['numero', 'id'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando el historial de la orden' . (!empty($args['numero']) ? ' ' . mb_strimwidth((string) $args['numero'], 0, 20, '…') : '') . '…';
    }

    public function run(array $args): ToolResult
    {
        $order = $this->resolve($args);
        $order->loadMissing(['payments', 'cancellations' => fn ($q) => $q->latest()]);
        $timeline = $this->history->timeline($order);

        $data = OrderFinder::summary($order) + array_filter([
            'entregada' => self::date($order->delivered_at),
            'cancelada' => self::date($order->cancelled_at),
            'programada_para' => self::date($order->scheduled_for),
            'novedad' => $order->novedad_type ? trim($order->novedad_type . ': ' . Present::mask((string) $order->novedad_description)) : null,
            'pagos' => $order->payments->map(fn ($p) => "{$p->method}: " . Present::value((float) $p->amount, 'money'))->all() ?: null,
            'motivo_de_cancelacion' => ($c = $order->cancellations->first())
                ? LossRules::reasonLabel($c->category ?: 'sin_lista') . ($c->reason ? ' — ' . mb_strimwidth(Present::mask((string) $c->reason), 0, 300, '…') : '')
                : null,
            'historial' => array_map(fn ($e) => [
                'cuando' => self::when($e['at']),
                'quien' => $e['who'],
                'que' => self::text($e['text']),
            ], $timeline['events']),
            'eventos_anteriores_sin_mostrar' => $timeline['total'] > count($timeline['events']) ? $timeline['total'] - count($timeline['events']) : null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        $events = array_slice($timeline['events'], -self::SCREEN_EVENTS);
        $display = [OrderFinder::block("Orden {$order->name}", [$order])];
        if ($events !== []) {
            $display[] = ['type' => 'list', 'title' => "Historial de {$order->name}" . ($timeline['total'] > count($events) ? ' (lo más reciente)' : ''),
                'items' => array_map(fn ($e) => ['text' => self::when($e['at']) . " · {$e['who']}: " . self::text($e['text'], 220)], $events)];
        }

        return new ToolResult($data, $display);
    }

    private function resolve(array $args): Order
    {
        if (isset($args['id']) && is_numeric($args['id']) && ($o = $this->finder->find((int) $args['id']))) {
            return $o;
        }
        $number = trim((string) ($args['numero'] ?? ''));
        if ($number === '') {
            throw new ToolProblem('Dime el número de la orden.');
        }
        $found = $this->finder->search($number, null);
        $digits = preg_replace('/\D+/', '', $number);
        // Si una coincide exacto con el número, esa
        $exact = $found->first(fn ($o) => (string) $o->order_number === $digits || ltrim((string) $o->name, '#') === ltrim($number, '#'));
        if ($exact) {
            return $exact;
        }
        if ($found->count() === 1) {
            return $found->first();
        }
        throw new ToolProblem($found->isEmpty()
            ? "No encontré la orden \"{$number}\"."
            : 'Hay varias órdenes con ese número: ' . $found->pluck('name')->implode(', ') . '. Pregúntale al dueño cuál.');
    }

    /** Una fecha de la orden (algunas columnas llegan como texto), "29/10/2026 07:37". */
    private static function date(mixed $value): ?string
    {
        return $value ? \Carbon\CarbonImmutable::parse($value)->format('d/m/Y H:i') : null;
    }

    /** "29/10 07:37". */
    private static function when(string $at): string
    {
        return strlen($at) >= 16 ? substr($at, 8, 2) . '/' . substr($at, 5, 2) . ' ' . substr($at, 11, 5) : $at;
    }

    /** El texto de un evento, sin datos de contacto del cliente y sin teléfonos. Público para probarlo. */
    public static function text(string $text, int $max = 300): string
    {
        $parts = array_map(fn ($p) => preg_match(self::PRIVATE_FIELDS, $p) ? 'Cambió datos de contacto o de ubicación del cliente' : $p, explode(' | ', $text));

        return mb_strimwidth(Present::mask(implode(' | ', array_unique($parts))), 0, $max, '…');
    }
}
