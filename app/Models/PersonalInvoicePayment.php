<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money received against a personal invoice. */
class PersonalInvoicePayment extends Model
{
    protected $fillable = ['personal_invoice_id', 'amount', 'paid_on', 'method', 'reference', 'account_id', 'money_transaction_id', 'source'];

    protected $casts = ['paid_on' => 'date'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PersonalInvoice::class, 'personal_invoice_id');
    }
}
