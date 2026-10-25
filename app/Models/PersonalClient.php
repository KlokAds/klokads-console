<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Someone I invoice for my own work. */
class PersonalClient extends Model
{
    protected $fillable = ['name', 'company', 'phone', 'email', 'address', 'ad_report_token', 'ad_campaigns', 'ad_bill', 'ad_fee_percent', 'ad_billed_until', 'ad_bill_basis', 'ad_meta_rate', 'ad_card_account_id'];

    protected $casts = ['ad_campaigns' => 'array', 'ad_bill' => 'boolean', 'ad_fee_percent' => 'float', 'ad_billed_until' => 'date', 'ad_meta_rate' => 'float'];

    /** The ad accounts or campaigns that are theirs. */
    public function adAssignments(): HasMany
    {
        return $this->hasMany(PersonalAdAssignment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PersonalInvoice::class);
    }
}
