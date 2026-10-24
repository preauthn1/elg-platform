<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WJackpotTicket extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at'
    ];
}
