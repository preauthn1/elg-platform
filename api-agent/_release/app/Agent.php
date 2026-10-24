<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{

    public $rules_search = [
        'id' => 'digits_between:0,10',
        'status' => 'boolean',
        'include_sub_account' => 'boolean',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'username' => 'required|r_alpha_num|between:4,20|unique:agents|unique:members',
        'nickname' => 'between:0,50',
        'email' => 'email|between:0,50',
        'password' => 'required|between:4,15|confirmed',
        'mobile' => 'between:0,20',
        'timezone_id' => 'digits_between:0,10',
        'country_id' => 'digits_between:0,10',
        'zip_code' => 'between:0,10',
        'district' => 'between:0,10',
        'city' => 'between:0,10',
        'address' => 'between:0,10',
        'email_validate' => 'required|boolean',
        'mobile_validate' => 'required|boolean',
        'frontend_register' => 'boolean',
        'has_chat' => 'boolean',
        'has_pc' => 'boolean',
        'has_mobile' => 'boolean',
        'has_online_payment' => 'boolean',
        'has_app' => 'boolean',
        'transaction_all' => 'boolean',
        'agent_backend_transaction' => 'boolean',
        'status' => 'boolean',
        'agent_mode' => 'boolean'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'nickname' => 'between:0,50',
        'email' => 'email|between:0,50',
        'password' => 'between:4,15|confirmed',
        'mobile' => 'between:0,20',
        'timezone_id' => 'digits_between:0,10',
        'country_id' => 'digits_between:0,10',
        'zip_code' => 'between:0,10',
        'district' => 'between:0,10',
        'city' => 'between:0,10',
        'address' => 'between:0,10',
        'email_validate' => 'boolean',
        'mobile_validate' => 'boolean',
        'frontend_register' => 'boolean',
        'has_chat' => 'boolean',
        'has_pc' => 'boolean',
        'has_mobile' => 'boolean',
        'has_online_payment' => 'boolean',
        'has_app' => 'boolean',
        'transaction_all' => 'boolean',
        'agent_backend_transaction' => 'boolean',
        'status' => 'boolean',
        'agent_mode' => 'boolean'
    ];

    public $rules_password_self = [
        'password' => 'required|between:4,15',
        'new_password' => 'required|between:4,15'
        // 'confirm_password' => 'different:new_password'
    ];

    public $rules_password = [
        'id' => 'required|digits_between:0,10',
        'new_password' => 'required|between:4,15'
        // 'confirm_password' => 'different:new_password'
    ];

    public $rules_confirm_password = [
        'password' => 'required'
    ];

    public $rules_delete = [
        'id' => 'required|digits_between:0,10'
    ];

    protected $fillable = [
        'parent',
        'host_id',
        'hierarchy',
        'code',
        'username',
        'balance',
        'email',
        'nickname',
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
        'email_validate',
        'mobile_validate',
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
        'admin',
        'created_by',
        'agent_mode'
    ];
}