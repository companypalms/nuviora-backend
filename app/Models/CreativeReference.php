<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `.92`: una referencia de la biblioteca (un video probado de otro anunciante, de Meta, TikTok, Instagram…), con su
 * número REF0001. La suben los editores; el líder o el dueño la pueden quitar (archivar).
 */
class CreativeReference extends Model
{
    protected $fillable = ['number', 'link', 'link_key', 'type', 'why', 'product_id', 'angle_code', 'file_id', 'uploaded_by', 'archived_at', 'archived_by'];

    protected $casts = ['archived_at' => 'datetime', 'number' => 'integer'];

    public function file()
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function pieces()
    {
        return $this->hasMany(CreativeBatchPiece::class, 'source_reference_id');
    }

    /** "REF0001". */
    public function code(): string
    {
        return self::codeOf($this->number);
    }

    public static function codeOf(int $number): string
    {
        return 'REF' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }
}
