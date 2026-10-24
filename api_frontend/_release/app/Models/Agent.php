<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{

    protected $fillable = [
        'parent',
        'code',
        'name',
        'email',
        'password',
        'mobile',
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
        'status',
        'last_login',
        'created_at',
        'updated_at',
        'admin'
    ];
}