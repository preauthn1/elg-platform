<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{

    protected $fillable = [
        'user_id',
        'member_id',
        'type',
        'platform',
        'amount',
        'user_limit_of_credit',
        'user_used_credit',
        'member_balance',
        'remark',
        'status',
        'third_party_result'
    ];

    public $rules_search = [
        'status' => 'boolean',
        'start_time' => 'required|date_format:Y-m-d H:i:s',
        'end_time' => 'required|date_format:Y-m-d H:i:s',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_create = [
        'member_id' => 'required|digits_between:0,10',
        'type' => 'required|in:1,2,3',
        'amount' => 'required|digits_between:0,10',
        'remark' => 'between:0,1024'
    ];

    public $rules_update = [
        'transaction_id' => 'required|digits_between:0,10',
        'remark' => 'between:0,1024'
    ];
    public $rules_confirm = [
        'transaction_id' => 'required|digits_between:0,10',
        'status' => 'in:1,2'
    ];
}