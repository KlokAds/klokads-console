<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A task I wrote down, reminded at its time (and again until done). */
class Task extends Model
{
    protected $fillable = ['title', 'note', 'due_at', 'repeat', 'remind_at', 'reminded', 'done_at'];

    protected $casts = ['due_at' => 'datetime', 'remind_at' => 'datetime', 'done_at' => 'datetime'];
}
