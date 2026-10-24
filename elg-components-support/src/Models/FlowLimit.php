<?php

namespace target\support\Models;

use Illuminate\Database\Eloquent\Model;

class FlowLimit extends Model
{
    protected $fillable = [
        'member_id',
        'type',
        'reference_id',
        'wallet_transaction_log_id',
        'flow_limit',
        'reach',
        'status',
        'admin'
    ];
}
