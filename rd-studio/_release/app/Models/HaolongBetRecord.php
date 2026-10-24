<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HaolongBetRecord extends Model
{

    protected $fillable = [
        'login_id',
        'bet_id',
        'bet_time',
        'utc_bet_time',
        'draw_type',
        'draw_date',
        'bet_number',
        'bet_position',
        'bet_amount',
        'strike',
        'admin'
    ];
}