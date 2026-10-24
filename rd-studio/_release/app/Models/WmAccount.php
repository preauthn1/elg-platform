<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WmAccount extends Model
{

    protected $fillable = [
        'vendor_info_id',
        'username',
        'user_id',
        'player_id',
        'balance',
        'member_id',
        'admin'
    ];
}