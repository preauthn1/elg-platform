<?php

namespace App\Models;

use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Member extends Authenticatable
{
    use Notifiable;

    public $rules_create = [
        'code' => 'required|between:4,10',
        'username' => 'required|r_alpha_num|between:4,10|unique:members|unique:agents',
        'password' => 'required|between:6,12|confirmed',
        'email' => 'email|between:0,60|unique:members',
        'nickname' => 'between:0,60|unique:members',
        'first_name' => 'between:0,35',
        'last_name' => 'r_alpha_dash|between:0,10',
        //'mobile' => 'digits_between:0,20|unique:mobile',
        'mobile' => 'digits_between:0,20',
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
        'first_name' => 'between:0,35',
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
    public $rules_payment_password = [
        'password' => 'between:6,12',
        'payment_password' => 'required|between:6,12|confirmed'
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

    public $rules_real_name = [
        'member_id' => 'required',
        'first_name' => 'required|between:3,50'
    ];

    /**
     * The attributes that are mass assignable.
     * 不要改成 $guarded, 因為VendorController.php, 那邊在塞資料時, 有一個vendor_balance, members沒有這欄位
     * @var array
     */
    protected $fillable = [
        'parent',
        'username',
        'email',
        'nickname',
        'password',
        'payment_password',
        'balance',
        'invitation_code',
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