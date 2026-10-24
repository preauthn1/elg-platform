<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'code' => 'required|between:0,10|r_alpha_num',
        'name' => 'required|between:0,30',
        'rate' => 'required|float',
        'names' => 'array',
        'status' => 'required|boolean'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'code' => 'between:0,10|r_alpha_num',
        'name' => 'between:0,30',
        'rate' => 'float',
        'names' => 'array',
        'status' => 'boolean'
    ];

    public $rules_name = [
        'language_id' => 'required|digits_between:0,10',
        'name' => 'required|between:0,30'
    ];

    public $rules_delete = [
        'id' => 'required|digits_between:0,10'
    ];

    protected $fillable = [
        'code',
        'name',
        'rate',
        'status',
        'admin'
    ];
}
