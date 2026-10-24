<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One notification: an automatic credit/debit, a reminder, an invoice event. The phone app reads these. */
class AppNotification extends Model
{
    protected $fillable = ['kind', 'title', 'body', 'url', 'tag', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];
}
