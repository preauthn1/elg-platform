<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class UserMenu extends Model
{

    protected $fillable = [
        'code',
        'name',
        'parent',
        'url',
        'symbol',
        'action',
        'admin'
    ];

    public $rules_auth = [
        'url' => 'required|between:0,30'
    ];
}