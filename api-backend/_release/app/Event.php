<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{

    protected $fillable = [
        'title',
        'content',
        'type_id',
        'agent_id',
        'status',
        'admin'
    ];

    public $rules_search = [
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'type_id' => 'required|digits_between:0,10',
        'title' => 'required|between:0,60',
        'content' => 'required',
        'languages' => 'array',
        'details' => 'array',
        'prize_type_id' => 'required|digits_between:0,10',
        'prize_name' => 'required|between:0,80',
        'prize_background' => 'required|base64image',
        'prize_number' => 'required|digits_between:0,10',
        'prize_expire_date' => 'required|date_format:Y-m-d H:i:s',
        'prizes' => 'required|array',
        'assign' => 'array'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'type_id' => 'digits_between:0,10',
        'title' => 'between:0,60',
        'languages' => 'array',
        'details' => 'array',
        'prize_type_id' => 'digits_between:0,10',
        'prize_name' => 'between:0,80',
        'prize_background' => 'base64image',
        'prize_number' => 'digits_between:0,10',
        'prize_expire_date' => 'date_format:Y-m-d H:i:s',
        'prizes' => 'array',
        'assign' => 'array'
    ];

    public $rules_lang = [
        'language_id' => 'required|digits_between:0,10',
        'title' => 'between:0,80'
    ];

    public $rules_detail = [
        'action_id' => 'required|digits_between:0,10',
        'order_type_id' => 'digits_between:0,10',
        'vendor_id' => 'digits_between:0,10',
        'point' => 'required|digits_between:0,10',
        'start_time' => 'required|date_format:Y-m-d H:i:s',
        'end_time' => 'required|date_format:Y-m-d H:i:s|after:start_time'
    ];

    public $rules_prize = [
        'name' => 'required|between:0,60',
        'number' => 'required|digits_between:0,10',
        'point' => 'required|digits_between:0,10',
        'percentage' => 'required|float'
    ];

    public $rules_assign = [
        'agent_id' => 'required|digits_between:0,10',
        'percentage' => 'required|float'
    ];

    // public $rules_detail = [
    // 'action_id' => 'required|digits_between:0,10',
    // 'order_type_id' => 'digits_between:0,10',
    // 'vendor_id' => 'digits_between:0,10',
    // 'game_id' => 'digits_between:0,10',
    // 'point' => 'required|numeric'
    // ];
}
