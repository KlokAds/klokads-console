<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One phone or browser that receives reminders. */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'content_encoding', 'device',
        'timezone', 'morning', 'morning_at', 'evening', 'evening_at', 'last_morning_on', 'last_evening_on',
    ];

    protected $casts = ['morning' => 'boolean', 'evening' => 'boolean', 'last_morning_on' => 'date', 'last_evening_on' => 'date'];

    protected $hidden = ['endpoint', 'public_key', 'auth_token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
