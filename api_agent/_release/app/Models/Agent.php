<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    public function event()
    {
        //一對多
        //return $this->hasMany('App\Models\Event');
        //多對多(需新增一張agent_event資料表, 並且塞入資料)
        return $this->belongsToMany('App\Models\Event');
        //多型多對多關聯
    }

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
        'has_chat' => 'boolean|nullable',
        'has_pc' => 'boolean|nullable',
        'has_mobile' => 'boolean|nullable',
        'has_online_payment' => 'boolean|nullable',
        'has_apk' => 'boolean|nullable',
        'has_ipa' => 'boolean|nullable',
        'transaction_all' => 'boolean',
        'agent_backend_transaction' => 'boolean|nullable',
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
        'has_chat' => 'boolean|nullable',
        'has_pc' => 'boolean|nullable',
        'has_mobile' => 'boolean|nullable',
        'has_apk' => 'boolean|nullable',
        'has_ipa' => 'boolean|nullable',
        'agent_backend_transaction' => 'boolean|nullable',
        'has_online_payment' => 'boolean',
        'transaction_all' => 'boolean',
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

    protected $guarded = [
        'id',
        'im_certify',
        'need_username'
    ];
}