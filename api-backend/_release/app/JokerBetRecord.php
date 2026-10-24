<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class JokerBetRecord extends Model
{

    protected $fillable = [
        'key',
        'code',
        'username',
        'game_code',
        'description',
        'type',
        'amount',
        'result',
        'bet_time',
        'app_id',
        'admin'
    ];
}
