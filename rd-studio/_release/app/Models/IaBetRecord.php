<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IaBetRecord extends Model
{

    protected $fillable = [
        'rowID',
        'activity_id',
        'betamount',
        'winamount',
        'money_type',
        'currency_id',
        'exchange_rate',
        'show_scale',
        'desc',
        'create_time',
        'username',
        'userip',
        'cdnip',
        'client_type',
        'order_id',
        'activity_name',
        'utc_create_time',
    ];
}