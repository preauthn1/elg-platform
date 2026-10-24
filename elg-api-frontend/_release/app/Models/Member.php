<?php

namespace App\Models;

use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Member extends Authenticatable
{
    use Notifiable;

    public $rules_create = [
        'username' => 'required|r_alpha_num|between:4,10|unique:members|unique:agents',
        'password' => 'required|between:6,12|confirmed',
        'email' => 'email|between:0,60|unique:members',
        'nickname' => 'between:0,60|unique:members',
        'first_name' => 'r_alpha_dash|between:0,15',
        'last_name' => 'r_alpha_dash|between:0,10',
        'mobile' => 'digits_between:0,20|unique:members',
        'timezone_id' => 'digits_between:0,10',
        'country_id' => 'digits_between:0,10',
        'zip_code' => 'digits_between:0,10',
        'district' => 'between:0,30',
        'city' => 'between:0,30',
        'address' => 'between:0,60'
    ];

    public $rules_update = [
        'email' => 'email|between:0,60',
        'nickname' => 'between:0,60',
        'first_name' => 'r_alpha_dash|between:0,15',
        'last_name' => 'r_alpha_dash|between:0,10',
        'timezone_id' => 'digits_between:0,10',
        'country_id' => 'digits_between:0,10',
        'zip_code' => 'digits_between:0,10',
        'district' => 'between:0,30',
        'city' => 'between:0,30',
        'address' => 'between:0,60'
    ];

    public $rules_password = [
        'password' => 'required|between:6,12',
        'new_password' => 'required|between:6,12|confirmed'
    ];

    public $rules_forget_password = [
        'username' => 'r_alpha_num|between:4,30',
        'email' => 'required|email|between:0,60',
        'birthday' => 'required|date_format:Y-m-d'
    ];

    public $rules_reset_password_check = [
        'token' => 'required'
    ];

    public $rules_reset_password = [
        'token' => 'required',
        'new_password' => 'required|between:6,12|confirmed'
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'parent',
        'username',
        'email',
        'nickname',
        'password',
        'balance',
        'device_id',
        'image',
        'first_name',
        'last_name',
        'birthday',
        'mobile',
        'timezone_id',
        'country_id',
        'zip_code',
        'district',
        'city',
        'address',
        'status',
        'event_mode',
        'change_to_single_wallet',
        'mobile_validate',
        'email_validate',
        'last_login',
        'created_at',
        'updated_at',
        'admin'
    ];
}