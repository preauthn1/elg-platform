<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Game extends Model
{
    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */

    public $rules_search = [
        'vendor_id' => 'digits_between:0,10',
        'type_id' => 'digits_between:0,10',
        'is_new' => 'boolean',
        'is_hot' => 'boolean',
        'status' => 'boolean',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'vendor_id' => 'required|digits_between:0,10',
        'type_id' => 'required|digits_between:0,10',
        'game_type' => 'required|between:0,30',
        'name' => 'required|between:0,30',
        'names' => 'array',
        'sort' => 'digits_between:0,10',
        'status' => 'required|boolean',
        'image' => 'base64image'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'vendor_id' => 'digits_between:0,10',
        'type_id' => 'digits_between:0,10',
        'game_type' => 'between:0,30',
        'name' => 'between:0,30',
        'names' => 'array',
        'sort' => 'digits_between:0,10',
        'status' => 'boolean',
        'image' => 'base64image'
    ];

    public $rules_name = [
        'language_id' => 'required|digits_between:0,10',
        'name' => 'required|between:0,30'
    ];

    public $rules_delete = [
        'id' => 'digits_between:0,10'
    ];

    protected $fillable = [
        'vendor_id',
        'type_id',
        'game_type',
        'name',
        'lottery_pool',
        'image',
        'sort',
        'is_new',
        'is_hot',
        'status',
        'admin'
    ];
}
