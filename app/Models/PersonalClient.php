<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Someone I invoice for my own work. */
class PersonalClient extends Model
{
    protected $fillable = ['name', 'company', 'phone', 'email', 'address'];

    public function invoices(): HasMany
    {
        return $this->hasMany(PersonalInvoice::class);
    }
}
