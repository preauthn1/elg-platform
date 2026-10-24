<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IsbBetRecord extends Model
{

    protected $fillable = [
        'playerid',
        'operator',
        'currency',
        'sessionid',
        'gameid',
        'roundid',
        'status',
        'channel',
        'type',
        'transactionid',
        'time',
        'amount',
        'balance',
        'jpc',
        'jpw',
        'jpw_jpc',
    ];
}