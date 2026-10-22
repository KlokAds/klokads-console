<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/** A movement on a client's prepaid balance: a top-up, a day's spend, or a manual adjustment. */
class ClientBalanceEntry extends Model
{
    protected $fillable = ['client_id', 'kind', 'amount', 'spend', 'commission', 'date', 'invoice_id', 'note'];

    /** Plain Y-m-d so day lookups work on every database. */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? CarbonImmutable::parse($value)->startOfDay() : null,
            set: fn ($value) => $value ? CarbonImmutable::parse($value)->toDateString() : null,
        );
    }
}
