<?php

namespace App\Models;

use App\Services\StripeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = ['name', 'company', 'email', 'phone', 'country', 'address', 'tax_id', 'meta_ad_account_id', 'meta_campaign_filter', 'spend_billing', 'billing_mode', 'currency', 'prepaid_buffer_days', 'prepaid_topup_days', 'prepaid_min_topup', 'spend_threshold', 'spend_billed_until', 'history_from', 'history_cursor', 'spend_synced_at', 'spend_attempted_at', 'spend_error', 'commission_rate', 'stripe_customer_id', 'stripe_test_customer_id', 'notes'];

    protected $casts = ['commission_rate' => 'float', 'spend_billing' => 'boolean', 'spend_billed_until' => 'date', 'history_from' => 'date', 'history_cursor' => 'date', 'spend_synced_at' => 'datetime', 'spend_attempted_at' => 'datetime'];

    /** The Stripe customer column for the mode of the connected key (test and live customers differ). */
    public static function customerColumn(): string
    {
        return StripeService::isLive() ? 'stripe_customer_id' : 'stripe_test_customer_id';
    }

    public function stripeCustomerId(): ?string
    {
        return $this->{self::customerColumn()};
    }

    /** Find a client by a Stripe customer ID from either mode. */
    public static function findByStripeCustomer(?string $customerId): ?self
    {
        if (! $customerId) {
            return null;
        }

        return static::where('stripe_customer_id', $customerId)->orWhere('stripe_test_customer_id', $customerId)->first();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(RecurringPlan::class);
    }

    public function paymentLinks(): HasMany
    {
        return $this->hasMany(PaymentLink::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->company ? "{$this->company} ({$this->name})" : $this->name;
    }

    /** Prepaid balance (cents): top-ups and adjustments minus spent days. */
    public function balance(): int
    {
        return (int) ClientBalanceEntry::where('client_id', $this->id)->sum('amount');
    }
}
