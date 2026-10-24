<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SboBetRecord extends Model
{

    protected $fillable = [
        'player_name',
        'vendor_code',
        'game_code',
        'parent_bet_id',
        'bet_id',
        'trans_type',
        'currency',
        'wallet_code',
        'bet_amount',
        'win_amount',
        'traceId',
        'create_time'
    ];
}