<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ncpay extends Model
{

    protected $fillable = [
        'order_id',
        'ncpay_deposit_id',
        'ncpay_order_id',
        'third_party_id',
        'bank',
        'amount',
        'fee',
        'index',
        'remark',
        'ncpay_status',
        'error',
        'code',
        'status',
        'admin'
    ];
}
