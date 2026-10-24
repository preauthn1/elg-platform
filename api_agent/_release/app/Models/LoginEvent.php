<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginEvent extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at'
    ];

    public $rules_search = [

    ];

    public $rules_create = [
        'title' => 'required|between:0,255',
        'content' => 'required|string',
        //'agent_id' => 'required',
        'type' => 'required|between:0,1,2',
        'how_days' => 'required|digits_between:0,2',
        'point' => 'required|digits_between:0,8',
        'times' => 'required|digits_between:0,8',
        'repeat_hour' => 'required|digits_between:0,4',
        'number_of_people' => 'required|digits_between:0,8',
        //'start_time' => 'required|datetime',
        //'end_time' => 'required|datetime',
        'vendor_id' => 'required|string',
        'status' => 'boolean'
    ];

    public $rules_update = [
        'title' => 'required|between:0,255',
        'content' => 'required|string',
        'type' => 'required|between:0,1,2',
        'how_days' => 'required|digits_between:0,2',
        'point' => 'required|digits_between:0,8',
        'times' => 'required|digits_between:0,8',
        'repeat_hour' => 'required|digits_between:0,4',
        'number_of_people' => 'required|digits_between:0,8',
        //'start_time' => 'required|datetime',
        //'end_time' => 'required|datetime',
        'vendor_id' => 'required|string',
        'status' => 'boolean'
    ];

    public $rules_name = [

    ];

    public $rules_status = [
        'id' => 'required',
        'status' => 'required'
    ];

    public $rules_show = [
        'id' => 'required'
    ];
}
