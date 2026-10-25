<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One movement in Airwallex: a Stripe payout landing, Meta charging the card, a fee… */
class AirwallexEntry extends Model
{
    protected $fillable = ['airwallex_id', 'kind', 'amount', 'currency', 'description', 'status', 'occurred_at', 'payout_id', 'client_id', 'raw'];

    protected $casts = ['occurred_at' => 'datetime', 'raw' => 'array'];

    public function payout()
    {
        return $this->belongsTo(Payout::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
