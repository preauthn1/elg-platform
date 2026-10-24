<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WithdrawalOrder extends Model
{

    protected $table = 'withdrawal_orders as wo';

    protected $guarded = ['id', 'create_at', 'update_at'];
}
