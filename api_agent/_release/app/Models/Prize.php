<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prize extends Model
{
    public function event()
    {
        return $this->belongsTo('App\Models\Event');
    }

    public $rules_search = [
        'status' => 'boolean'
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'number' => 'required|digits_between:0,10',
        'expire_date' => 'required|digits_between:0,10',
        'is_repeatedly' => 'required|digits_between:0,10',
        'expire_date' => 'date_format:Y-m-d H:i:s',
        'name' => 'required|between:0,30',
        'names' => 'array',
        'status' => 'required|boolean'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'number' => 'required|digits_between:0,10',
        'is_repeatedly' => 'required|digits_between:0,10',
        'expire_date' => 'date_format:Y-m-d H:i:s',
        'draw_date' => 'date_format:Y-m-d H:i:s',
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
        'event_id',
        'type_id',
        'name',
        'background',
        'number',
        'is_repeatedly',
        'flow_limit_multiple',
        'withdrawal_cancel_reward',
        'expire_date',
        'draw_date',
        'info',
        'status',
        'admin'
    ];
}