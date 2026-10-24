<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MtBetRecord extends Model
{

    protected $fillable = [
        'rowID',
        'playerName',
        'gameDate',
        'gameCode',
        'gameType',
        'betAmount',
        'winAmount',
        'commissionable',
        'roomFee',
        'income',
        'progressive_wins',
        'progressive_share',
        'merchantId',
        'utc_gameDate',
    ];
}