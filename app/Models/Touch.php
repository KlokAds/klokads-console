<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Someone I want to keep in touch with: how often, and when it suits them. */
class Touch extends Model
{
    protected $fillable = ['name', 'emoji', 'phone', 'every_days', 'window', 'last_talked_at', 'talk_hours', 'planned_on', 'remind_at', 'reminded', 'paused', 'sort'];

    protected $casts = ['last_talked_at' => 'datetime', 'remind_at' => 'datetime', 'planned_on' => 'date', 'talk_hours' => 'array', 'paused' => 'boolean'];
}
