<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class PgBetRecord extends Model
{
    protected $fillable = [
       'TransactionId',
       'ProductGroup',
       'ExternalUserId',
       'GamesessionId',
       'GameId',
       'Currency',
       'ExternalTransactionId',
       'Balance',
       'NumRounds',
       'TotalLoss',
       'TotalGain',
       'GamesessionStarted',
       'GamesessionFinished',
       'ExchangeRate',
       'ContextId',
       'ClientIP',
       'ExternalFreegameId',
       'JackpotLoss', 
       'JackpotGain',
       'FreegameRounds',
       'FreegameBet',
       'FreegameWin',
       'Channel',
       'MessageId',
       'MessageTimestamp',
    ];
}