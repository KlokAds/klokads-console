<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Money lent to someone (they owe me) or borrowed from someone (I owe them). */
class Loan extends Model
{
    protected $fillable = ['direction', 'person', 'phone', 'currency', 'principal', 'fee', 'account_id', 'started_on', 'due_on', 'note', 'money_transaction_id'];

    protected $casts = ['started_on' => 'date', 'due_on' => 'date'];

    public function payments(): HasMany
    {
        return $this->hasMany(LoanPayment::class)->orderBy('paid_on');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function repaid(): int
    {
        return (int) ($this->relationLoaded('payments') ? $this->payments->sum('amount') : $this->payments()->sum('amount'));
    }

    public function outstanding(): int
    {
        return max(0, $this->principal - $this->repaid());
    }
}
