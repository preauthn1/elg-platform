<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Member extends Model
{

    protected $fillable = [
        'user_id',
        'device_id',
        'third_party_id',
        'username',
        'nickname',
        'thumbnail',
        'last_login',
        'status',
        'admin'
    ];

    public $rules_create = [
        'third_party_id' => 'required|r_alpha_num|between:4,32|unique:members',
        'username' => 'required|r_alpha_num|between:4,32|unique:members',
        'nickname' => 'between:0,50',
        'thumbnail' => 'base64image'
    ];

    public $rules_update = [
        'username' => 'r_alpha_num|between:4,32|unique:members',
        'nickname' => 'between:0,50',
        'thumbnail' => 'base64image',
        'status' => 'boolean'
    ];

    public $rules_show = [
        'username' => 'required|r_alpha_num|between:4,32'
    ];

    public $rules_view = [
        'member_id' => 'required|digits_between:0,10'
    ];
}