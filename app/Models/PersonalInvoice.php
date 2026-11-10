<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An invoice for my own work, paid by bank transfer, mobile wallet or Bangla QR. */
class PersonalInvoice extends Model
{
    protected $fillable = [
        'number', 'personal_client_id', 'personal_recurring_id', 'carried_into_id', 'carried_amount', 'currency', 'items', 'total', 'carried', 'paid', 'status',
        'issued_on', 'due_on', 'paid_on', 'public_token', 'note', 'sent_at', 'sent_via', 'sent_to', 'reported',
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

    /** Unpaid invoices carried into this one. */
    public function previous(): HasMany
    {
        return $this->hasMany(self::class, 'carried_into_id')->orderBy('issued_on');
    }

    public function carriedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'carried_into_id');
    }

    /** This invoice's own work plus the previous dues it carries. */
    public function grand(): int
    {
        return $this->total + $this->carried;
    }

    public function due(): int
    {
        return $this->status === 'carried' ? 0 : max(0, $this->grand() - $this->paid);
    }

    public function publicUrl(): string
    {
        return route('personal.invoice.public', $this->public_token);
    }
}
