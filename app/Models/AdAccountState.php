<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An ad account's billing at the last look: spent in all and not charged yet (to see Meta's charges). */
class AdAccountState extends Model
{
    protected $fillable = ['account_id', 'currency', 'amount_spent', 'unbilled', 'card', 'checked_at'];

    protected $casts = ['checked_at' => 'datetime'];
}
