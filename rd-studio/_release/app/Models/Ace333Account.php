<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ace333Account extends Model
{

    protected $fillable = [
        'vendor_info_id',
        'username',
        'password',
        'player_id',
        'member_id',
        'admin'
    ];
}