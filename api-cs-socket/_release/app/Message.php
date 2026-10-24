<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{

    protected $fillable = [
        'room_id',
        'user_id',
        'member_id',
        'type',
        'content',
        'red_envelope_id',
        'transaction_id',
        'status'
    ];
}