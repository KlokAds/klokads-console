<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A monthly job (e.g. EDGE ৳5,000 a month since the 9th): pay day is the start day each
 * month, and the invoice for the month just worked is made a few days before it.
 */
class PersonalRecurring extends Model
{
    protected $table = 'personal_recurring';

    protected $fillable = ['personal_client_id', 'currency', 'items', 'day', 'start_on', 'due_days', 'lead_days', 'auto_send', 'active', 'next_on', 'note'];

    protected $casts = ['items' => 'array', 'auto_send' => 'boolean', 'active' => 'boolean', 'next_on' => 'date', 'start_on' => 'date'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(PersonalClient::class, 'personal_client_id');
    }
}
