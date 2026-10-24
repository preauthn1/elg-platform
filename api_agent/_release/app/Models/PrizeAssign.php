<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrizeAssign extends Model
{

    protected $fillable = [
        'event_id',
        'agent_id',
        'percentage',
        'status',
        'admin'
    ];
}
