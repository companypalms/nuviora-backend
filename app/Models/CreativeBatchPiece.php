<?php

namespace App\Models;

use App\Services\Meta\Queue\QueueRules;
use Illuminate\Database\Eloquent\Model;

/** Una pieza de un encargo (un anuncio de Meta): qué cambia en ella y, al aprobar, su número C####. */
class CreativeBatchPiece extends Model
{
    protected $fillable = ['creative_batch_id', 'number', 'position', 'change', 'format'];

    public function batch()
    {
        return $this->belongsTo(CreativeBatch::class, 'creative_batch_id');
    }

    /** "C0457", o null si todavía no se aprobó. */
    public function code(): ?string
    {
        return $this->number !== null ? QueueRules::pieceCode($this->number) : null;
    }
}
