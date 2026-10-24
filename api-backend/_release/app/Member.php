<?php

namespace App;

use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Member extends Authenticatable
{
    use Notifiable;

    public $rules = [
        'name' => 'required|unique:users',
        'email' => 'required|email'
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'parent',
        'username',
        'balance',
        'email',
        'nickname',
        'password',
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
        'is_validate',
        'email_validate',
        'mobile_validate',
        'last_login',
        'created_at',
        'updated_at',
        'admin'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [ // 'password',
    ];
}