<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventDetail extends Model
{

    protected $fillable = [
        'action_id',
        'order_type_id',
        'vendor_id',
        'game_id',
        'rate',
        'point',
        'start_time',
        'end_time',
        'admin'
    ];

    public $rules_search = [
        'detail_id' => 'required|digits_between:0,10',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];
}