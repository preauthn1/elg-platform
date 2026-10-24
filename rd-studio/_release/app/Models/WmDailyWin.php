<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WmDailyWin extends Model
{

    protected $fillable = [
        'player_name',
        'player_token',
        'skin',
        'currency',
        'cash_bet',
        'cash_win',
        'bonus_bet',
        'bonus_win',
        'free_round_bet',
        'free_round_win',
        'jack_pot_bet',
        'jack_pot_win',
        'gamerounds',
        'freerounds',
        'date'
    ];
}