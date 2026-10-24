<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A message I haven't seen or a call I haven't returned — reminded after an hour. */
class FollowUp extends Model
{
    protected $fillable = ['source', 'kind', 'person', 'phone', 'key', 'preview', 'count', 'first_at', 'last_at', 'seen_at', 'done_at', 'remind_at', 'reminded', 'note'];

    protected $casts = ['first_at' => 'datetime', 'last_at' => 'datetime', 'seen_at' => 'datetime', 'done_at' => 'datetime', 'remind_at' => 'datetime'];

    public function scopeOpen($query)
    {
        return $query->whereNull('seen_at')->whereNull('done_at');
    }
}
