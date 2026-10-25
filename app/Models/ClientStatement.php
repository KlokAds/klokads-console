<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A prepaid client's statement for a period: drafted automatically, emailed when I send it. */
class ClientStatement extends Model
{
    protected $fillable = ['client_id', 'period_start', 'period_end', 'data', 'show_fee', 'status', 'sent_to', 'sent_at'];

    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'data' => 'array', 'show_fee' => 'boolean', 'sent_at' => 'datetime'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
