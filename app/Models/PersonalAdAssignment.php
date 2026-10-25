<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An ad account (or one campaign in it) that belongs to one of my own clients. */
class PersonalAdAssignment extends Model
{
    protected $fillable = ['personal_client_id', 'account_id', 'account_name', 'currency', 'campaign_id', 'campaign_name', 'paid_by', 'from_date'];

    protected $casts = ['from_date' => 'date'];

    public function client()
    {
        return $this->belongsTo(PersonalClient::class, 'personal_client_id');
    }
}
