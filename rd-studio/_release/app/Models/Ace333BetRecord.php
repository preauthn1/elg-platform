<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ace333BetRecord extends Model
{

    protected $fillable = [
        'playerID',
        'extPlayerID',
        'gameID',
        'transactionType',
        'referenceID',
        'status',
        'created',
        'updated',
        'betAmount',
        'winAmount',
        'jackpotModule',
        'jackpotContributionAmt',
        'currency',
        'resultUrl',
        'roundDetails',
        'platform',
        'admin'
    ];
}