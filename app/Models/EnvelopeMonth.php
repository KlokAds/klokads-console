<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One month's hand-out to an envelope — the source account was debited for it. */
class EnvelopeMonth extends Model
{
    protected $fillable = ['envelope_id', 'month', 'assigned', 'assigned_on', 'account_id', 'money_transaction_id'];

    protected $casts = ['assigned_on' => 'date'];

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }
}
