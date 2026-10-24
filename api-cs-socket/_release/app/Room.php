<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{

    protected $fillable = [
        'type',
        'code',
        'name',
        'thumbnail',
        'status'
    ];

    public $rules_search = [
        'status' => 'in:1,2',
    ];

    public $rules_history = [
        'code' => 'required',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_status = [
        'code' => 'required',
        'status' => 'required|in:1,2',
    ];

    public $rules_read = [
        'code' => 'required',
        'message_id' => 'required|digits_between:0,10'
    ];
    public $rules_send = [
        'code' => 'required',
        'type' => 'required|in:1,2,3,4,5',
        'content' => 'required'
    ];
}