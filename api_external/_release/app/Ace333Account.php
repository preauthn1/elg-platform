<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Ace333Account extends Model
{

    protected $fillable = [
        'usename',
        'password',
        'player_id',
        'balance',
        'member_id',
    ];
}