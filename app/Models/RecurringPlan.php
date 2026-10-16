<?php

namespace App\Models;

use App\Models\Concerns\HasStripeMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringPlan extends Model
{
    use HasStripeMode;

    protected $guarded = [];

    protected $casts = ['current_period_end' => 'datetime'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Amount normalised to one month, for MRR. */
    public function monthlyAmount(): float
    {
        $perMonth = ['day' => 30, 'week' => 52 / 12, 'month' => 1, 'year' => 1 / 12][$this->interval] ?? 1;

        return $this->amount * $perMonth / max(1, $this->interval_count);
    }
}
