<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GreatWallBetRecord extends Model
{

    protected $fillable = [
        'themeId',
        'gameNo',
        'gameSerialId',
        'accountId',
        'userName',
        'valueBefore',
        'valueAfter',
        'betCoin',
        'winAmount',
        'availTotalBet',
        'availTotalWin',
        'remoteIP',
        'deviceType',
        'historyCreatedTime',
        'utc_created_time'
    ];
}
