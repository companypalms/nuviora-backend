<?php

namespace App\Services\Assistant;

use App\Models\Order;
use App\Services\Metrics\PersonName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Busca órdenes para el asistente, como el buscador de la pantalla Órdenes: por número ("9003", "#P9003") o por el
 * cliente (nombre o teléfono). El teléfono solo se usa para buscar: nunca va a la IA. Del cliente, la IA recibe el
 * nombre y la inicial del apellido ("María P."); la pantalla, el nombre completo.
 */
class OrderFinder
{
    public const MAX = 10;

    private const WITH = ['client:id,first_name,last_name,city', 'status:id,description', 'agent:id,names,surnames',
        'agency:id,names', 'deliverer:id,names,surnames', 'city:id,name', 'shop:id,name'];

    /** @return Collection<int, Order> las más nuevas primero */
    public function search(?string $number, ?string $client, int $limit = self::MAX): Collection
    {
        $number = trim((string) $number);
        $client = trim((string) $client);
        if ($number === '' && $client === '') {
            return collect();
        }
        $query = Order::with(self::WITH)->orderByDesc('created_at')->orderByDesc('id');
        if ($number !== '') {
            $digits = preg_replace('/\D+/', '', $number);
            $query->where(function (Builder $q) use ($number, $digits) {
                $q->where('name', 'like', '%' . ltrim($number, '#') . '%');
                if ($digits !== '') {
                    $q->orWhere('order_number', $digits)->orWhere('id', $digits)->orWhere('name', 'like', "%{$digits}");
                }
            });
        }
        if ($client !== '') {
            $digits = preg_replace('/\D+/', '', $client);
            $query->whereHas('client', function (Builder $q) use ($client, $digits) {
                // Un teléfono (7 números o más): se busca por los últimos 7, así da igual cómo lo escribió
                if (strlen($digits) >= 7) {
                    $q->whereRaw("REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ?", ['%' . substr($digits, -7) . '%']);
                } else {
                    $q->whereRaw("CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) LIKE ?", ["%{$client}%"]);
                }
            });
        }

        return $query->limit($limit)->get();
    }

    public function find(int $id): ?Order
    {
        return Order::with(self::WITH)->find($id);
    }

    /** "María P.": lo que la IA sabe del cliente. */
    public static function clientShort(Order $o): ?string
    {
        $first = trim((string) $o->client?->first_name);
        $last = trim((string) $o->client?->last_name);

        return $first === '' && $last === '' ? null : trim($first . ($last !== '' ? ' ' . mb_substr($last, 0, 1) . '.' : ''));
    }

    /** Lo principal de una orden, para la IA. */
    public static function summary(Order $o): array
    {
        return array_filter([
            'id' => $o->id,
            'numero' => $o->name,
            'creada' => $o->created_at?->format('d/m/Y H:i'),
            'estado' => $o->status?->description,
            'total' => Present::value((float) $o->current_total_price, 'money'),
            'cliente' => self::clientShort($o),
            'ciudad' => $o->city?->name ?? $o->client?->city,
            'tienda' => $o->shop?->name,
            'vendedora' => $o->agent ? PersonName::of($o->agent->names, $o->agent->surnames) : null,
            'agencia' => $o->agency?->names,
            'repartidor' => $o->deliverer ? PersonName::of($o->deliverer->names, $o->deliverer->surnames) : null,
            'es_devolucion' => $o->is_return ? true : null,
            'es_cambio' => $o->is_exchange ? true : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Las órdenes para la pantalla, con su botón "Abrir". */
    public static function block(string $title, iterable $orders): array
    {
        $rows = [];
        foreach ($orders as $o) {
            $rows[] = [
                'id' => $o->id,
                'name' => $o->name,
                'status' => $o->status?->description,
                'client' => trim("{$o->client?->first_name} {$o->client?->last_name}") ?: null,
                'city' => $o->city?->name ?? $o->client?->city,
                'total' => (float) $o->current_total_price,
                'created' => $o->created_at?->format('d/m/Y'),
            ];
        }

        return ['type' => 'orders', 'title' => $title, 'orders' => $rows];
    }
}
