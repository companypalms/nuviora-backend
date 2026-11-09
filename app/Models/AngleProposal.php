<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `.116`: un ángulo que la IA le propone al dueño cuando a un producto no le quedan ángulos disponibles en su ficha. Él
 * decide: ese ángulo, otro que recomiende o uno de los que ya están. Hasta entonces no se piden batches nuevos del producto.
 */
class AngleProposal extends Model
{
    public const PENDING = 'pending';

    /** Lo que puede decidir: hacer ese ángulo, otro que él escriba o uno de los que ya están. */
    public const CHOICES = ['accepted', 'other', 'existing'];

    protected $table = 'meta_angle_proposals';

    protected $fillable = ['product_id', 'code', 'name', 'about', 'why', 'status', 'decided_angle', 'decided_by', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
