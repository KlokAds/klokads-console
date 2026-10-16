<?php

namespace App\Models;

use App\Models\Concerns\HasStripeMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentLink extends Model
{
    use HasStripeMode;

    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
