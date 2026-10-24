<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Advertisement extends Model
{

    public $rules_search = [
        'path_id' => 'numeric',
        'start_time' => 'required|date_format:Y-m-d H:i:s',
        'end_time' => 'required|date_format:Y-m-d H:i:s|after:start_time',
        'language_id' => 'numeric',
        'country_id' => 'numeric',
        'agent_id' => 'numeric',
        'status' => 'boolean'
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'path_id' => 'required|digits_between:0,10',
        'language_id' => 'required|array|min:1',
        // 'country_id' => 'digits_between:0,10',
        // 'agent_id' => 'digits_between:0,10',
        'url' => 'url|between:0,60',
        'game_id' => 'digits_between:0,10',
        'game_type_id' => 'digits_between:0,10',
        'vendor_id' => 'digits_between:0,10',
        'sort' => 'digits_between:0,10',
        'start_time' => 'required|date_format:Y-m-d H:i:s',
        'end_time' => 'required|date_format:Y-m-d H:i:s|after:start_time',
        'image' => 'array|min:1',
        'image_pc' => 'array|min:1'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'path_id' => 'digits_between:0,10',
        'language_id' => 'required|array|min:1',
        // 'country_id' => 'digits_between:0,10',
        // 'agent_id' => 'digits_between:0,10',
        'url' => 'url|between:0,60',
        'game_id' => 'digits_between:0,10',
        'game_type_id' => 'digits_between:0,10',
        'vendor_id' => 'digits_between:0,10',
        'sort' => 'digits_between:0,10',
        'start_time' => 'date_format:Y-m-d H:i:s',
        'end_time' => 'date_format:Y-m-d H:i:s|after:start_time',
        'image' => 'array|min:1',
        'image_pc' => 'array|min:1'
    ];

    protected $guarded = [
        'id',
        'event_id',
        'created_at',
        'updated_at'
    ];
}