<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class MessageRead extends Model
{

    protected $fillable = [
        'room_id',
        'message_id',
        'member_id'
    ];
}
