<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What Meta took from my card for a client's ads (e.g. 2,501.94 taka) — billed to them once. */
class PersonalAdCharge extends Model
{
    protected $fillable = ['personal_client_id', 'date', 'currency', 'amount', 'note', 'personal_ad_bill_id'];

    protected $casts = ['date' => 'date'];
}
