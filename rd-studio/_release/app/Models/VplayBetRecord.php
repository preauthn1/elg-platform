<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VplayBetRecord extends Model
{
    protected $fillable = [
        'WinloseID',
        'MemberAccount',
        'AgentAccount',
        'GameTypeID',
        'GameTypeName',
        'GameID',
        'GameENAME',
        'GameNameZHCN',
        'Bet',
        'WinLose',
        'GongPoint',
        'BetLM',
        'WinLoseLM',
        'GetJPMoney',
        'BetTime',
        'utc_bet_time',
        'ReCalcuTime',
        'utc_recalcu_time',
        'BetState',
        'Memo',
    ];
}