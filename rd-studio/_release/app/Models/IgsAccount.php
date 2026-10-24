<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IgsAccount extends Model
{

    protected $fillable = [
        'vendor_info_id',
        'username',
        'player_id',
        'password',
        'balance',
        'member_id',
        'admin'
    ];
}