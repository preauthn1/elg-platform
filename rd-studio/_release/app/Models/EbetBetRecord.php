<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EbetBetRecord extends Model
{

    protected $fillable = [
        'username',
        'userId',
        'gameType',
        'betHistoryId',
        'bet',
        'validBet',
        'payout',
        'balance',
        'payoutWithoutholding',
        'niuniuWithHoldingTotal',
        'betMap',
        'roundNo',
        'payoutDetail',
        'judgeResult',
        'playerCards',
        'playerResult',
        'bankerCards',
        'bankerResult',
        'allDices',
        'dragonCard',
        'tigerCard',
        'number',
        'niuniuResult',
        'niuniuWithHoldingDetail',
        'createTime',
        'utc_create_time',
        'payoutTime',
        'utc_payout_time',
        'platform',
    ];
}