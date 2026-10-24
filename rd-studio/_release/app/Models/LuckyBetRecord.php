<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LuckyBetRecord extends Model
{

    protected $fillable = [
        'round_id',
        'userId',
        'account',
        'cur',
        'mid',
        'gid',
        'tid',
        'bet',
        'win',
        'left',
        'isGamble',
        'isFreeSpin',
        'freeSpinNum',
        'time',
        'bet_time',
    ];
}