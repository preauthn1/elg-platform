<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class TransactionLog extends Model
{

    protected $fillable = [
        'transaction_no',
        'member_id',
        'vendor_id',
        'order_id',
        'type',
        'point',
        'vendor_point',
        'rate',
        'balance',
        'vendor_balance',
        'status',
        'remark',
        'result',
        'operator',
        'admin'
    ];
}