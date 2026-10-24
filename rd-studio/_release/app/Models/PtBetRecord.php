<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PtBetRecord extends Model
{

    protected $fillable = [
        'player_name',
        'window_code',
        'game_id',
        'game_code',
        'game_type',
        'game_name',
        'session_id',
        'currency_code',
        'bet',
        'win',
        'progress_ive_bet',
        'progress_ive_win',
        'balance',
        'current_bet',
        'game_date',
        'utc_game_date',
        'info',
        'live_network',
        'rnum',
    ];
}