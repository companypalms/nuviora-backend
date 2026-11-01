<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una nota del dueño en "Lo que sabemos" de un producto (`.66`): lo que funciona, lo que no o una idea para probar. */
class ProductNote extends Model
{
    public const KINDS = ['works' => 'Funciona', 'fails' => 'No funciona', 'idea' => 'Idea para probar'];

    protected $fillable = ['product_id', 'kind', 'text', 'source', 'created_by', 'archived_at'];

    protected $casts = ['archived_at' => 'datetime'];

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
