<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnlimitWithdrawLog extends Model
{
    protected $table = 'unlimit_withdrawlog';

    protected $fillable = ['order_no', 'member_id', 'reward_point', 'withdrawn_point', 'created_at', 'updated_at', 'amount', 'type', 'remark', 'admin'];
    
    public $rules_search = [
    	'start_time' => 'required|date',
    	'end_time' => 'required|date',
        'member' => 'string',
        'order_no' => 'string'
    ];
   
}
