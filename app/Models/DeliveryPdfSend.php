<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** `.106`: el PDF de un producto que se le mandó (o se intentó mandar) a un cliente al entregarle. Uno por cliente y producto. */
class DeliveryPdfSend extends Model
{
    public const SENT = 'sent';
    public const FAILED = 'failed';

    protected $fillable = ['client_id', 'product_id', 'order_id', 'status', 'attempts', 'error', 'last_attempt_at', 'sent_at'];

    protected $casts = [
        'attempts' => 'integer',
        'last_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
