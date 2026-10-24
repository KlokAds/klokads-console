<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Spent out of an envelope (e.g. Songsar → bazar ৳3,200). Touches no account: the money already left. */
class EnvelopeSpend extends Model
{
    protected $fillable = ['envelope_id', 'month', 'purpose', 'amount', 'spent_on', 'note'];

    protected $casts = ['spent_on' => 'date'];

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }
}
