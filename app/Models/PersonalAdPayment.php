<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Money a client paid me for their ads — any amount; what is not on a bill yet is their credit. */
class PersonalAdPayment extends Model
{
    protected $fillable = ['personal_client_id', 'date', 'amount', 'applied', 'account_id', 'money_transaction_id', 'note'];

    protected $casts = ['date' => 'date'];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
