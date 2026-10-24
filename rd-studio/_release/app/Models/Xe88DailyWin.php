<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Xe88DailyWin extends Model
{

    protected $fillable = [
        'date',
        'username',
        'bets',
        'wins',
        'turnover',
        'houseearnings',
    ];
}