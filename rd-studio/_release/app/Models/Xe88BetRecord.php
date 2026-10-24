<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Xe88BetRecord extends Model
{

    protected $fillable = [
        'username',
        'game_id',
        'game_name',
        'bet',
        'win',
        'begin_money',
        'end_money',
        'game_log',
        'log_time',
        'utc_log_time',
    ];
}