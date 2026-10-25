<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The days of a client's ads that my card paid, and the invoice that bills them — each day billed once. */
class PersonalAdBill extends Model
{
    protected $fillable = ['personal_client_id', 'period_from', 'period_to', 'currency', 'spend', 'basis', 'usd', 'fx_rate', 'personal_invoice_id'];

    protected $casts = ['period_from' => 'date', 'period_to' => 'date'];

    public function invoice()
    {
        return $this->belongsTo(PersonalInvoice::class, 'personal_invoice_id');
    }
}
