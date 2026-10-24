<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnlimitBonusreportAgent extends Model
{
    protected $fillable = [
        'status',
        'payout_time',
        'remark'
    ];
}
