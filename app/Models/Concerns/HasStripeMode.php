<?php

namespace App\Models\Concerns;

use App\Services\StripeService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Records synced from Stripe belong to test or live mode. Queries only see the
 * mode of the key currently connected, and new records are stamped with it.
 */
trait HasStripeMode
{
    public static function bootHasStripeMode(): void
    {
        static::addGlobalScope('stripe_mode', function (Builder $query) {
            $query->where($query->qualifyColumn('livemode'), StripeService::isLive());
        });

        static::creating(function ($model) {
            if ($model->livemode === null) {
                $model->livemode = StripeService::isLive();
            }
        });
    }

    public function initializeHasStripeMode(): void
    {
        $this->mergeCasts(['livemode' => 'boolean']);
    }
}
