<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mega888Account extends Model
{

    protected $fillable = [
        'vendor_info_id',
        'username',
        'player_id',
        'user_id',
        'balance',
        'member_id',
        'admin'
    ];
}