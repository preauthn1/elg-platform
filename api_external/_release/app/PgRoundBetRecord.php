<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class PgRoundBetRecord extends Model
{
    protected $fillable = [
       'TransactionId',
       'Status',
       'Amount',
       'Time',
       'ProductGroup',
       'ExternalUserId',
       'GamesessionId',
       'GamesessionState',
       'GameId',
       'RoundId',
       'RoundData',
       'RoundLoss',
       'JackpotLoss',
       'JackpotGain',
       'Currency ',
       'ExternalTransactionId',
       'Balance',
       'NumRounds', 
       'TotalLoss',
       'TotalGain',
       'ExternalFreegameId',
       'Channel',
       'MessageId',
       'MessageTimestamp',
    ];
}