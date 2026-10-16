<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Stripe balance transaction: every cent that moves through the Stripe balance. */
class Transaction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'available_on' => 'datetime',
        'occurred_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
