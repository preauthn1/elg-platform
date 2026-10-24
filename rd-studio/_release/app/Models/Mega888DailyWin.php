<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mega888DailyWin extends Model
{

    protected $fillable = [
        'vendor_info_id',
        'date',
        'type',
        'loginId',
        'bet',
        'win',
        'yieId',
        'userId',
        'name',
        'memo',
        'tel',
    ];
}