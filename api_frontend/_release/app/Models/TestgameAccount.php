<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TestgameAccount extends Model
{

    protected $fillable = [
        'vendor_info_id',
        'username',
        'player_id',
        'balance',
        'member_id',
        'status',
        'admin'
    ];
}