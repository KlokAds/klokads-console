<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A monthly invoice created automatically on a set day. */
class PersonalRecurring extends Model
{
    protected $table = 'personal_recurring';

    protected $fillable = ['personal_client_id', 'currency', 'items', 'day', 'due_days', 'auto_send', 'active', 'next_on', 'note'];

    protected $casts = ['items' => 'array', 'auto_send' => 'boolean', 'active' => 'boolean', 'next_on' => 'date'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(PersonalClient::class, 'personal_client_id');
    }
}
