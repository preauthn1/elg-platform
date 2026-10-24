<?php
namespace App;

use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use Notifiable;

    public $rules_search = [
        'status' => 'boolean',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_create = [
        'parent' => 'digits_between:0,10',
        'username' => 'required|r_alpha_num|between:4,10|unique:users',
        'nickname' => 'between:0,50',
        'password' => 'required|between:4,15|confirmed',
        'email' => 'required|email|between:0,50',
        'mobile' => 'digits_between:0,20',
        'timezone_id' => 'digits_between:0,10',
        'status' => 'boolean'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'parent' => 'digits_between:0,10',
        'nickname' => 'between:0,50',
        'password' => 'between:4,15|confirmed',
        'email' => 'email|between:0,50',
        'mobile' => 'digits_between:0,20',
        'timezone_id' => 'digits_between:0,10',
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

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'parent',
        'username',
        'nickname',
        'email',
        'password',
        'mobile',
        'timezone_id',
        'status',
        'last_login',
        'created_at',
        'updated_at',
        'admin'
    ];
}
