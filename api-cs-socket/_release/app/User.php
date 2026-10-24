<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'role_id',
        'parent_id',
        'host_id',
        'username',
        'password',
        'share_code',
        'domain',
        'API_USER',
        'API_KEY',
        'nickname',
        'thumbnail',
        'limit_of_credit',
        'used_credit',
        'transaction_type',
        'withdraw_confirm',
        'last_login',
        'status',
        'admin'
    ];

    public $rules_search = [
        'role_id' => 'in:2,3',
        'status' => 'boolean',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_view = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'parent_id' => 'digits_between:0,10',
        'username' => 'required|r_alpha_num|between:4,10|unique:users',
        'nickname' => 'between:0,50',
        'password' => 'required|between:4,15|confirmed',
        'thumbnail' => 'base64image',
        'transaction_type' => 'boolean',
        'withdraw_confirm' => 'boolean',
        'status' => 'boolean'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'parent_id' => 'digits_between:0,10',
        'password' => 'between:4,15|confirmed',
        'nickname' => 'between:0,50',
        'thumbnail' => 'base64image',
        'transaction_type' => 'boolean',
        'withdraw_confirm' => 'boolean',
        'status' => 'boolean'
    ];

    public $rules_password_self = [
        'password' => 'required|between:4,15',
        'new_password' => 'required|between:4,15|confirmed'
    ];

    public $rules_password = [
        'id' => 'required|digits_between:0,10',
        'new_password' => 'required|between:4,15|confirmed'
    ];

    public $rules_delete = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_transaction = [
        'id' => 'required|digits_between:0,10',
        'amount' => 'integer',
        'remark' => 'between:0,50'
    ];

    public $rules_unlimitBonus = [
        'from_username' => 'required|String',
        'to_username' => 'required|String',
        'amount' => 'float',
        'remark' => 'between:0,50'
    ];
}