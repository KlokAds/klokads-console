<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An invoice for my own work, paid by bank transfer, mobile wallet or Bangla QR. */
class PersonalInvoice extends Model
{
    protected $fillable = [
        'number', 'personal_client_id', 'personal_recurring_id', 'currency', 'items', 'total', 'paid', 'status',
        'issued_on', 'due_on', 'paid_on', 'public_token', 'note', 'sent_at', 'reported',
    ];

    protected $casts = ['items' => 'array', 'reported' => 'array', 'issued_on' => 'date', 'due_on' => 'date', 'paid_on' => 'date', 'sent_at' => 'datetime'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(PersonalClient::class, 'personal_client_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PersonalInvoicePayment::class)->orderBy('paid_on');
    }

    public function due(): int
    {
        return max(0, $this->total - $this->paid);
    }

    public function publicUrl(): string
    {
        return route('personal.invoice.public', $this->public_token);
    }
}
