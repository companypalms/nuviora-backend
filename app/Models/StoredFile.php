<?php

namespace App\Models;

use App\Services\Files\FileStore;
use App\Services\Files\StoredFileRules;
use Illuminate\Database\Eloquent\Model;

/**
 * `.90`: un archivo guardado en el almacén (R2 o, en la PC de pruebas, la carpeta privada): una pieza que entregó un
 * editor, una foto o el documento de estrategia de un producto. Se sube directo desde el navegador con una URL firmada
 * (queda "pending") y se confirma al terminar ("ready").
 */
class StoredFile extends Model
{
    protected $fillable = ['driver', 'path', 'name', 'mime', 'size', 'status', 'kind', 'product_id', 'creative_batch_piece_id', 'version', 'uploaded_by'];

    protected $casts = ['size' => 'integer', 'version' => 'integer'];

    public function piece()
    {
        return $this->belongsTo(CreativeBatchPiece::class, 'creative_batch_piece_id');
    }

    /**
     * Para las pantallas: el nombre, el tipo y los enlaces firmados para verlo y para bajarlo (vencen en 6 h). Sin
     * almacén (se apagó), sin enlaces.
     */
    public function payload(): array
    {
        $store = FileStore::for($this->driver);

        return [
            'id' => $this->id, 'name' => $this->name, 'mime' => $this->mime, 'size' => $this->size,
            'type' => StoredFileRules::type($this->mime), 'version' => $this->version,
            'url' => $store?->viewUrl($this, false), 'download_url' => $store?->viewUrl($this, true),
        ];
    }

    /** @return array<int, list<array>> las fotos de cada producto (listas), por producto */
    public static function photosOf(array $productIds): array
    {
        return self::whereIn('product_id', array_map('intval', $productIds) ?: [0])->where('kind', 'photo')->where('status', 'ready')->orderBy('id')->get()
            ->groupBy('product_id')->map(fn ($files) => $files->map(fn (self $f) => $f->payload())->values()->all())->all();
    }

    /** @return array<int, array> los archivos listos, por id */
    public static function payloads(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === [] ? [] : self::whereIn('id', $ids)->where('status', 'ready')->get()
            ->mapWithKeys(fn (self $f) => [$f->id => $f->payload()])->all();
    }
}
