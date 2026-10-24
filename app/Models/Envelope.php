<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Money handed out every month for one purpose (e.g. Songsar ৳15,000 from bKash), spent down by purpose. */
class Envelope extends Model
{
    protected $fillable = ['name', 'emoji', 'currency', 'amount', 'account_id', 'purposes', 'day', 'active', 'sort'];

    protected $casts = ['purposes' => 'array', 'active' => 'boolean'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function months(): HasMany
    {
        return $this->hasMany(EnvelopeMonth::class);
    }

    public function spends(): HasMany
    {
        return $this->hasMany(EnvelopeSpend::class);
    }
}
