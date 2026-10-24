<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnlimitWithdrawLog extends Model
{
    //
    protected $table = 'unlimit_withdrawlog';

    protected $fillable = ['order_no', 'member_id', 'reward_point', 'withdrawn_point', 'amount', 'type', 'remark', 'admin'];
    
    public $rules_search = [
        'member_id' => 'required|domain'
    ];
    
    public $rules_create = [
    	'amount' => 'required|numeric|min:0.01'
    ];
}
