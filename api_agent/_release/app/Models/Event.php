<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    //
    public function prize()
    {
        //一對一
        return $this->hasOne('App\Models\Prize');
    }

    public function eventName()
    {
        return $this->hasMany('App\Models\EventName');
    }

    public function agent()
    {
        //多對多
        //return $this->belongsToMany('App\Models\Agent');
    }

    public function scopeEnable($query)
    {
        return $query->where('status', 1);
    }
    public function scopeDisable($query)
    {
        return $query->where('status', 0);
    }
    public function scopeOfType($query, $type)
    {
        return $query->where('type_id', $type);
    }
    public function scopeTypeId($query)
    {
        return $query->where('type_id', 1);
    }

    protected $fillable = [
        'title',
        'content',
        'type_id',
        'agent_id',
        'status',
        'admin'
    ];

    // rule
    public $rules_search = [
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_vendor = [
        'vendor_id' => 'required|digits_between:0,10',
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        //活动类型
        'type_id' => 'required|digits_between:0,10',
        //名称/内容
        'title' => 'required|between:0,60',
        'content' => 'required',
        'languages' => 'array',
        //设定条件
        'details' => 'array',
        //奖励内容
        'prize_type_id' => 'required|digits_between:0,10',
        'prize_name' => 'required|between:0,80',
        'prize_background' => 'required|base64image',
        'prize_number' => 'required|digits_between:0,10',
        'prize_expire_date' => 'required|date_format:Y-m-d H:i:s',
        'prizes' => 'required|array',
        'assign' => 'array'
    ];

    public $rules_new_create = [
        //活动类型
        'type_id' => 'required|digits_between:0,10',
        //名称/内容
        'title' => 'required|between:0,60',
        'content' => 'required',
        'languages' => 'array',
        //设定条件
        'details' => 'required|array',
        //奖励内容
        'prize_type_id' => 'required|digits_between:0,10',
        'prize_name' => 'required|between:0,80',
        'prize_number' => 'required|digits_between:0,10',
        'prize_expire_date' => 'required|date_format:Y-m-d H:i:s',
        'prizes' => 'required|array'
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

    public $rules_new_update = [
        'id' => 'required|digits_between:0,10',
        'type_id' => 'required|digits_between:0,10',
        'title' => 'required|between:0,60',
        'content' => 'required',
        'languages' => 'array',
        'details' => 'required|array',
        'prize_type_id' => 'digits_between:0,10',
        'prize_name' => 'between:0,80',
        'prize_number' => 'digits_between:0,10',
        'prize_expire_date' => 'date_format:Y-m-d H:i:s',
        'prizes' => 'array'
    ];

    public $rules_status = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_lang = [
        'language_id' => 'required|digits_between:0,10',
        'title' => 'between:0,80'
    ];

    public $rules_detail = [
        //条件选择方式； 投注金额/赢得金额
        'action_id' => 'required|digits_between:0,10',
        //选择限定的储值方式
        //'order_type_id' => 'digits_between:0,10',
        'order_type_id' => 'array',
        //选择限定的游戏商
        'vendor_id' => 'digits_between:0,10',
        //选择限定的游戏類型
        'game_type_id' => 'digits_between:0,10',
        //需大于等于多少点数
        'point' => 'required|digits_between:0,10',
        //选择活动区间
        'start_time' => 'required|date_format:Y-m-d H:i:s',
        'end_time' => 'required|date_format:Y-m-d H:i:s|after:start_time'
    ];

    public $rules_prize = [
        'name' => 'required|between:0,60',
        'number' => 'required',
        'point' => 'required|digits_between:0,10',
        'percentage' => 'required|float'
    ];

    public $rules_new_prize = [
        'name' => 'required|between:0,60',
        'type' => 'required',
        'number' => 'required',
        'point' => 'required|digits_between:0,10',
        'point_percentage' => 'required|digits_between:0,3',
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