<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{

    protected $fillable = [
        'title',
        'content',
        'type_id',
        'agent_id',
        'point',
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

    public $rules_event = [
        'event_id' => 'required|digits_between:0,10'
    ];
}