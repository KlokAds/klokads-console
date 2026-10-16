<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = ['name', 'company', 'email', 'phone', 'country', 'stripe_customer_id', 'notes'];

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
}
