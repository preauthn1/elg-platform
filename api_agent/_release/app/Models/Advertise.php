<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Advertise extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at'
    ];

    public $rules_search = [
        'path_id' => 'array',
        //'start_time' => 'required|date_format:Y-m-d H:i:s',
        //'end_time' => 'required|date_format:Y-m-d H:i:s|after:start_time',
        'language_id' => 'numeric',
        'agent_id' => 'numeric',
        'status' => 'boolean'
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'path_id' => 'required|digits_between:0,10',
        'agent_id' => 'required|digits_between:0,10',
        'language_id' => 'required|array|min:1',
        'type' => 'required|digits_between:0,10',
        'status' => 'required|digits_between:0,10',
        'sort' => 'required|digits_between:0,10',
        //'sort' => 'digits_between:0,10',
        'start_time' => 'required|date_format:Y-m-d H:i:s',
        'end_time' => 'required|date_format:Y-m-d H:i:s|after:start_time',
        'image' => 'array|min:1',
        'image_id' => 'array|min:1',
        'image_pc' => 'array|min:1',
        'image_pc_id' => 'array|min:1'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'path_id' => 'required|digits_between:0,10',
        'agent_id' => 'required|digits_between:0,10',
        'language_id' => 'required|array|min:1',
        'type' => 'required|digits_between:0,10',
        'status' => 'required|digits_between:0,10',
        'sort' => 'required|digits_between:0,10',
        //'sort' => 'digits_between:0,10',
        'start_time' => 'required|date_format:Y-m-d H:i:s',
        'end_time' => 'required|date_format:Y-m-d H:i:s|after:start_time',
        'image' => 'array|min:1',
        'image_id' => 'array|min:1',
        'image_pc' => 'array|min:1',
        'image_pc_id' => 'array|min:1'
    ];
}
