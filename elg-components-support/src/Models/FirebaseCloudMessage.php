<?php

namespace target\support\Models;

use Illuminate\Database\Eloquent\Model;

class FirebaseCloudMessage extends Model
{

    protected $fillable = [
        'member_id',
        'title',
        'content',
        'url',
        'multicast_id',
        'message_id',
        'error',
        'status',
    ];
}
