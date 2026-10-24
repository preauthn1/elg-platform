<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionLogAgent extends Model
{

    protected $fillable = [
        'from_agent_id',
        'to_agent_id',
        'type',
        'amount',
        'from_limit_of_credit',
        'from_used_credit',
        'to_limit_of_credit',
        'to_used_credit',
        'operator',
        'platform',
        'remark',
        'status',
    ];
}