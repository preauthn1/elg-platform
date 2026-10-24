<?php

namespace App\Models;

use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Member extends Authenticatable
{
    use Notifiable;

    public $rules_search = [
        'status' => 'boolean',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'parent' => 'required|digits_between:0,10',
        'username' => 'required|r_username|between:4,10|unique:members|unique:agents',
        'password' => 'required|r_alpha_num|between:4,10|confirmed',
        'payment_password' => 'r_alpha_num|between:4,10|confirmed',
        'email' => 'email|between:0,60',
        'nickname' => 'between:0,60',
        'first_name' => 'between:0,35',
        //'last_name' => 'r_alpha_dash|between:0,10',
        'mobile' => 'digits_between:0,20',
        'timezone_id' => 'digits_between:0,10',
        'country_id' => 'digits_between:0,10',
        'zip_code' => 'digits_between:0,10',
        'district' => 'between:0,30',
        'city' => 'between:0,30',
        'address' => 'between:0,60',
        'status' => 'boolean'
    ];

    public $rules_update = [
        'password' => 'r_alpha_num|between:4,10|confirmed',
        'payment_password' => 'r_alpha_num|between:4,10|confirmed',
        'email' => 'email|between:0,60',
        'nickname' => 'between:0,60',
        'first_name' => 'between:0,35',
        //'last_name' => 'r_alpha_dash|between:0,10',
        'mobile' => 'digits_between:0,20',
        'timezone_id' => 'digits_between:0,10',
        'country_id' => 'digits_between:0,10',
        'zip_code' => 'digits_between:0,10',
        'district' => 'between:0,30',
        'city' => 'between:0,30',
        'address' => 'between:0,60',
        'status' => 'boolean',
        'is_validate' => 'boolean',
        'mobile_validate' => 'boolean'
    ];

    public $rules_password = [
        'id' => 'required|digits_between:0,10',
        'new_password' => 'required|r_alpha_num|between:4,10|confirmed'
    ];

    public $rules_delete = [
        'id' => 'required|digits_between:0,10'
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'id',
        'created_at',
        'updated_at'
    ];
}