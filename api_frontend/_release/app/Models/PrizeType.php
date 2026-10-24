<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrizeType extends Model
{

    public $rules_search = [
        'status' => 'boolean'
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'name' => 'required|between:0,30',
        'names' => 'array',
        'status' => 'required|boolean'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'name' => 'between:0,30',
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
        'name',
        'status',
        'admin'
    ];
}
