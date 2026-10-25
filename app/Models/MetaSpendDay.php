<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/** One campaign's spend on one day, read from Meta. */
class MetaSpendDay extends Model
{
    protected $fillable = ['client_id', 'campaign_id', 'campaign_name', 'date', 'spend', 'currency', 'impressions', 'clicks', 'reach', 'results', 'result_type', 'invoice_id'];

    /** Stored as a plain Y-m-d so lookups and comparisons by day work on every database. */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? CarbonImmutable::parse($value)->startOfDay() : null,
            set: fn ($value) => $value ? CarbonImmutable::parse($value)->toDateString() : null,
        );
    }
}
