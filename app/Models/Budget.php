<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Monthly spending limit for one category in one currency. */
class Budget extends Model
{
    protected $fillable = ['category', 'currency', 'amount'];
}
