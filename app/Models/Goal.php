<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A savings target; progress is the balance of its savings account. */
class Goal extends Model
{
    protected $fillable = ['name', 'account_id', 'target', 'due_on', 'done'];

    protected $casts = ['due_on' => 'date', 'done' => 'boolean'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
