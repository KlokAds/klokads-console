<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payout extends Model
{
    protected $guarded = [];

    protected $casts = [
        'arrival_at' => 'datetime',
        'occurred_at' => 'datetime',
    ];
}
