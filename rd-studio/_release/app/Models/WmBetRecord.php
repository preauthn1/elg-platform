<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WmBetRecord extends Model
{

    protected $fillable = [
        'round_id',
        'game_identity',
        'currency',
        'wallet_type',
        'external_reference',
        'external_user_id',
        'user_token',
        'session_token',
        'start_time',
        'end_time',
        'initial_balance',
        'finial_balance',
        'bet_amount',
        'win_amount',
        'jack_pot_bet',
        'jack_pot_win',
        'utc_start_time',
        'utc_end_time'
    ];
}