<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgBetRecord extends Model
{

    protected $fillable = [
        'data_type',
        'bill_no',
        'player_name',
        'agent_code',
        'game_code',
        'net_amount',
        'bet_time',
        'utc_bet_time',
        'game_type',
        'bet_amount',
        'valid_bet_amount',
        'flag',
        'play_type',
        'currency',
        'table_code',
        'login_ip',
        'recalcu_time',
        'utc_recalcu_time',
        'platform_type',
        'remark',
        'round',
        'result',
        'before_credit',
        'device_type',
        'game_category',
        'cancel_reason',
        'admin'
    ];
}