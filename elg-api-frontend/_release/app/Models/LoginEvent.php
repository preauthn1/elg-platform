<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginEvent extends Model
{

    protected $fillable = [
        'agent_id',
        'type',
        'how_days',
        'point',
        'times',
        'number_of_people',
        'start_time',
        'end_time',
        'repeat_hour',
        'admin'
    ];
}