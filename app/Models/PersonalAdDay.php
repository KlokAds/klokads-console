<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/** One of my clients' campaigns on one day: spend and results, in the ad account's currency. */
class PersonalAdDay extends Model
{
    protected $fillable = ['personal_client_id', 'account_id', 'campaign_id', 'campaign_name', 'date', 'currency', 'spend', 'impressions', 'clicks', 'reach', 'results', 'result_type', 'paid_by'];

    /** Stored as a plain Y-m-d so lookups by day work on every database. */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? CarbonImmutable::parse($value)->startOfDay() : null,
            set: fn ($value) => $value ? CarbonImmutable::parse($value)->toDateString() : null,
        );
    }
}
