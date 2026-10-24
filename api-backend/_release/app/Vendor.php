<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'name' => 'required|between:0,30',
        'rate' => 'required|float',
        'percentage' => 'required|float',
        'image' => 'required|base64image',
        'app_icon' => 'base64image',
        'sort' => 'digits_between:0,10',
        'status' => 'required|in:0,1,2',
        'valid_bet_rate' => 'required|float'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'name' => 'between:0,30',
        'rate' => 'float',
        'percentage' => 'float',
        'image' => 'base64image',
        'app_icon' => 'base64image',
        'sort' => 'digits_between:0,10',
        'status' => 'in:0,1,2',
        'valid_bet_rate' => 'required|float'
    ];

    protected $fillable = [
        'name',
        'rate',
        'percentage',
        'image',
        'app_icon',
        'sort',
        'status',
        'valid_bet_rate'
    ];
}