<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `.168`: una oficina de MRW o Zoom donde el cliente retira su encomienda. La lista sale de las páginas públicas de cada
 * empresa (`couriers:oficinas`); el dueño puede apagar una o agregar otra en "Formulario de la tienda".
 */
class CourierOffice extends Model
{
    protected $fillable = ['company', 'state', 'city', 'name', 'address', 'code', 'is_active', 'source'];

    protected $casts = ['is_active' => 'boolean'];

    /** Cómo queda escrita en la orden: "MRW Alto Barinas — URB ALTO BARINAS NORTE…, Barinas (Barinas)". */
    public function label(): string
    {
        $address = rtrim(trim((string) $this->address), '.');
        // La ciudad, si la dirección no termina ya con ella ("…LOCAL 135, BARINAS")
        $city = $this->city && !str_ends_with(mb_strtoupper($address), mb_strtoupper($this->city)) ? $this->city : '';
        $where = trim($city . ($this->state && $this->state !== $this->city ? " ({$this->state})" : ''));

        return mb_substr("{$this->company} {$this->name}" . ($address !== '' ? " — {$address}" : '') . ($where !== '' ? ", {$where}" : ''), 0, 300);
    }
}
