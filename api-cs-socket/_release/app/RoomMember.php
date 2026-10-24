<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class RoomMember extends Model
{

    protected $fillable = [
        'room_id',
        'member_id',
        'status'
    ];
}
