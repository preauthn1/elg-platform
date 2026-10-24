<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{

    public $rules_search = [
        'parent' => 'numeric',
        'status' => 'boolean',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'username' => 'required|r_alpha_num|between:4,10|unique:agents',
        'nickname' => 'between:0,50',
        'email' => 'required|email|between:0,50',
        'password' => 'required|between:4,15|confirmed',
        'mobile' => 'between:0,20',
        'percentage' => 'between:0,99.99',
        'timezone_id' => 'digits_between:0,10',
        'country_id' => 'digits_between:0,10',
        'zip_code' => 'between:0,10',
        'district' => 'between:0,10',
        'city' => 'between:0,10',
        'address' => 'between:0,10',
        'frontend_register' => 'boolean',
        'has_chat' => 'boolean',
        'has_pc' => 'boolean',
        'has_mobile' => 'boolean',
        'has_online_payment' => 'boolean',
        'has_app' => 'boolean',
        'transaction_all' => 'boolean',
        'agent_backend_transaction' => 'boolean',
        'status' => 'boolean'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'nickname' => 'between:0,50',
        'email' => 'email|between:0,50',
        'password' => 'between:4,15|confirmed',
        'mobile' => 'between:0,20',
        'percentage' => 'between:0,99.99',
        'timezone_id' => 'digits_between:0,10',
        'country_id' => 'digits_between:0,10',
        'zip_code' => 'between:0,10',
        'district' => 'between:0,10',
        'city' => 'between:0,10',
        'address' => 'between:0,10',
        'frontend_register' => 'boolean',
        'has_chat' => 'boolean',
        'has_pc' => 'boolean',
        'has_mobile' => 'boolean',
        'has_online_payment' => 'boolean',
        'has_app' => 'boolean',
        'transaction_all' => 'boolean',
        'agent_backend_transaction' => 'boolean',
        'status' => 'boolean'
    ];

    public $rules_delete = [
        'id' => 'required|digits_between:0,10'
    ];

    protected $fillable = [
        'parent',
        'hierarchy',
        'code',
        'username',
        'nickname',
        'email',
        'password',
        'cs_sync_flag',
        'mobile',
        'percentage',
        'CS_API_USER',
        'CS_API_KEY',
        'timezone_id',
        'country_id',
        'zip_code',
        'district',
        'city',
        'address',
        'frontend_register',
        'has_chat',
        'has_pc',
        'has_mobile',
        'has_online_payment',
        'has_app',
        'transaction_all',
        'agent_backend_transaction',
        'status',
        'last_login',
        'created_at',
        'updated_at',
        'admin'
    ];
}