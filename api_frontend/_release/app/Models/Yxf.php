<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Yxf extends Model
{

    protected $fillable = [
        'order_id',
        'api_amount',
        'real_amount',
        'url',
        'bank_card_id',
        'bank_user_name',
        'state',
        'remark',
        'status',
        'admin'
    ];
}
