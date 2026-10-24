<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrizeContent extends Model
{

    protected $fillable = [
        'prize_id',
        'name',
        'number',
        'sent',
        'percentage',
        'status',
        'admin'
    ];
}
