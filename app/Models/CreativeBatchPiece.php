<?php

namespace App\Models;

use App\Services\Meta\Queue\QueueRules;
use Illuminate\Database\Eloquent\Model;

/**
 * Una pieza de un encargo (un anuncio de Meta): qué cambia en ella y, al aprobar, su número C####. Al entregar guarda el
 * enlace de Drive y el hook de la última entrega (paso 5a).
 */
class CreativeBatchPiece extends Model
{
    protected $fillable = ['creative_batch_id', 'number', 'position', 'change', 'format', 'link', 'hook',
        // .81: qué prueba, el formato que prueba, su hipótesis, por qué ese formato y, al terminar el test, si ganó
        'test', 'style', 'hypothesis', 'reason', 'result', 'result_at'];

    protected $casts = ['result_at' => 'datetime'];

    public function batch()
    {
        return $this->belongsTo(CreativeBatch::class, 'creative_batch_id');
    }

    /** `.92`: la referencia de la biblioteca de la que salió (si salió de una). */
    public function sourceReference()
    {
        return $this->belongsTo(CreativeReference::class, 'source_reference_id');
    }

    /** "C0457", o null si todavía no se aprobó. */
    public function code(): ?string
    {
        return $this->number !== null ? QueueRules::pieceCode($this->number) : null;
    }
}
