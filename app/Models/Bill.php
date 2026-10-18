<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A monthly payment (rent, EMI, family support…) that can be marked paid in one tap. */
class Bill extends Model
{
    protected $fillable = ['name', 'account_id', 'category', 'amount', 'day', 'counterparty', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
