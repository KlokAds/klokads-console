<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One bank / wallet SMS or email alert and what became of it. */
class InboxMessage extends Model
{
    protected $fillable = ['source', 'sender', 'body', 'hash', 'received_at', 'parsed', 'status', 'account_id', 'money_transaction_id', 'personal_invoice_id', 'note'];

    protected $casts = ['parsed' => 'array', 'received_at' => 'datetime'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
