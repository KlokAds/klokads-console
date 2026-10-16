<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    protected $fillable = [
        'client_id', 'category', 'vendor', 'description', 'amount',
        'original_amount', 'original_currency', 'paid_via', 'reference', 'spent_on',
    ];

    protected $casts = ['spent_on' => 'date'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
