<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A top-up drafted automatically, waiting for my approval before the client's card is charged. */
class ChargeRequest extends Model
{
    protected $fillable = ['client_id', 'kind', 'amount', 'suggested', 'currency', 'description', 'status', 'invoice_id', 'error', 'reason', 'decided_at'];

    protected $casts = ['reason' => 'array', 'decided_at' => 'datetime'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
