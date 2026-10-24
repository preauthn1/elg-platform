<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class RedEnvelope extends Model
{

    protected $fillable = [
        'room_id',
        'member_id',
        'type',
        'amount',
        'number',
        'cancel_time',
        'remark',
        'status',
        'third_party_result',
        'created_user',
        'updated_user'
    ];

    public $rules_cs = [
        'start_time' => 'required|date_format:Y-m-d H:i:s',
        'end_time' => 'required|date_format:Y-m-d H:i:s',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_search = [
        'member_id' => 'required|digits_between:0,10',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];


    public $rules_show = [
        'red_envelope_id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'room_id' => 'required|digits_between:0,10',
        'type' => 'required|in:1,2,3',
        'amount' => 'required|digits_between:0,10',
        'number' => 'required|digits_between:0,10',
        'cancel_hour' => 'required|digits_between:0,2',
        'remark' => 'between:0,1024'
    ];

    public $rules_password = [
        'red_envelope_id' => 'required|digits_between:0,10',
        'password' => 'required|r_alpha_num'
    ];

    public $rules_update = [
        'red_envelope_id' => 'required|digits_between:0,10',
        'remark' => 'between:0,1024'
    ];

    public $rules_cancel = [
        'red_envelope_id' => 'required|digits_between:0,10',
    ];
}