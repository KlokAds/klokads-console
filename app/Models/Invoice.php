<?php

namespace App\Models;

use App\Models\Concerns\HasStripeMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Invoice extends Model
{
    use HasStripeMode;

    protected $guarded = [];

    protected $casts = [
        'issued_at' => 'datetime',
        'due_at' => 'datetime',
        'paid_at' => 'datetime',
        'lines' => 'array',
        'details' => 'array',
        'invoice_sent_at' => 'datetime',
        'receipt_sent_at' => 'datetime',
    ];

    protected $hidden = ['public_token'];

    protected $appends = ['public_url'];

    public function getPublicUrlAttribute(): ?string
    {
        return $this->exists ? $this->publicUrl() : null;
    }

    /** Secret, unguessable link token for the client-facing page. Created on first use. */
    public function publicToken(): string
    {
        if (! $this->public_token) {
            $this->forceFill(['public_token' => Str::random(40)])->saveQuietly();
        }

        return $this->public_token;
    }

    /** The link we send clients: our own invoice / receipt page with card payment. */
    public function publicUrl(): string
    {
        return route('public.invoice', $this->publicToken());
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
