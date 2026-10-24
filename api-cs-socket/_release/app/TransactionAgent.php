<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class TransactionAgent extends Model
{

    protected $fillable = [
        'from',
        'to',
        'type',
        'amount',
        'from_limit_of_credit',
        'from_used_credit',
        'to_limit_of_credit',
        'to_used_credit',
        'remark',
        'status',
    ];
}