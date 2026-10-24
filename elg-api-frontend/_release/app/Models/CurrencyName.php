<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurrencyName extends Model
{
    protected $fillable = [
        'language_id',
        'currency_id',
        'name',
        'admin'
    ];
}
