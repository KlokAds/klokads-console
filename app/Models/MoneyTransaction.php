<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money in, money out, or moved/converted between two accounts. */
class MoneyTransaction extends Model
{
    protected $fillable = [
        'kind', 'account_id', 'to_account_id', 'amount', 'to_amount', 'fee', 'base_amount',
        'category', 'counterparty', 'description', 'occurred_on', 'expense_id', 'fee_expense_id', 'payout_id', 'bill_id',
    ];

    protected $casts = ['occurred_on' => 'date'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }
}
