<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VpowerBetRecord extends Model
{

    protected $fillable = [
        'uid',
        'uniqleid',
        'gameid',
        'tableid',
        'username',
        'pay',
        'win',
        'startscore',
        'finalscore',
        'time',
        'utc_time',
    ];
}