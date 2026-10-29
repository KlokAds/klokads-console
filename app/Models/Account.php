<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A bank account, wallet, card or cash pot — business or personal, in any currency. */
class Account extends Model
{
    protected $fillable = ['name', 'scope', 'kind', 'inv_type', 'expected_rate', 'maturity_on', 'current_value', 'value_at', 'purpose', 'provider', 'last4', 'app_profile', 'in_fee_pct', 'in_fee_fixed', 'out_fee_pct', 'out_fee_fixed', 'currency', 'opening_balance', 'business_share', 'opening_rate', 'notes', 'bank_balance', 'bank_balance_at', 'guide', 'archived'];

    protected $casts = ['opening_rate' => 'float', 'archived' => 'boolean', 'bank_balance_at' => 'datetime', 'maturity_on' => 'date', 'value_at' => 'datetime', 'expected_rate' => 'float', 'current_value' => 'integer', 'in_fee_pct' => 'float', 'out_fee_pct' => 'float', 'in_fee_fixed' => 'integer', 'out_fee_fixed' => 'integer', 'business_share' => 'integer'];

    public function outgoing(): HasMany
    {
        return $this->hasMany(MoneyTransaction::class);
    }

    public function incoming(): HasMany
    {
        return $this->hasMany(MoneyTransaction::class, 'to_account_id');
    }
}
