<?php

namespace App\Models;

use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Member extends Authenticatable
{
    use Notifiable;

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
        'uuid',
        'status',
        'is_validate',
        'mobile_validate',
        'email_validate',
        'last_login',
        'created_at',
        'updated_at',
        'admin'
    ];
}