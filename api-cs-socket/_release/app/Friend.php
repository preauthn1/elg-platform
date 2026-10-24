<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Friend extends Model
{

    protected $fillable = [
        'self_id',
        'other_id',
        'nickname',
        'room_id',
        'status'
    ];

    public $rules_search = [
        'status' => 'required|in:1,2',
        // 'page' => 'required|numeric',
        // 'per_page' => 'required|numeric'
    ];

    public $rules_view = [
        'member_id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'member_id' => 'required|digits_between:0,10',
        'nickname' => 'between:0,50'
    ];

    public $rules_update = [
        'friend_id' => 'required|digits_between:0,10',
        'nickname' => 'between:0,50',
        'status' => 'in:1,2'
    ];

    public $rules_delete = [
        'friend_id' => 'required|digits_between:0,10'
    ];
}