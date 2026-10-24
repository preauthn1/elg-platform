<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AesBetRecord extends Model
{

    protected $fillable = [
        'gameType',
        'winAmount',
        'bet_time',
        'realWinAmount',
        'updateTime',
        'realBetAmount',
        'userId',
        'betType',
        'platform',
        'txStatus',
        'betAmount',
        'platformTxId',
        'gameCode',
        'currency',
        'jackpotWinAmount',
        'bet_id',
        'jackpotBetAmount',
        'turnover',
        'roundId'
    ];
}
