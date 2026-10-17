<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A bank account, wallet, card or cash pot — business or personal, in any currency. */
class Account extends Model
{
    protected $fillable = ['name', 'scope', 'kind', 'currency', 'opening_balance', 'opening_rate', 'notes', 'archived'];

    protected $casts = ['opening_rate' => 'float', 'archived' => 'boolean'];

    public function outgoing(): HasMany
    {
        return $this->hasMany(MoneyTransaction::class);
    }

    public function incoming(): HasMany
    {
        return $this->hasMany(MoneyTransaction::class, 'to_account_id');
    }
}
