<?php

namespace App\Models;

use App\Models\Concerns\HasStripeMode;
use Illuminate\Database\Eloquent\Model;

class Payout extends Model
{
    use HasStripeMode;

    protected $guarded = [];

    protected $casts = [
        'arrival_at' => 'datetime',
        'occurred_at' => 'datetime',
    ];
}
