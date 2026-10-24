<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrizeMember extends Model
{

    protected $fillable = [
        'member_id',
        'prize_id',
        'content_id',
        'status',
        'admin'
    ];
}
