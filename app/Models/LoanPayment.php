<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A repayment on a loan, either way. */
class LoanPayment extends Model
{
    protected $fillable = ['loan_id', 'amount', 'paid_on', 'account_id', 'note', 'money_transaction_id'];

    protected $casts = ['paid_on' => 'date'];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
