<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pussy888DailyWin extends Model
{
    protected $fillable = [
        'vendor_info_id',
        'date',
        'player_id',
        'win'
    ];
}