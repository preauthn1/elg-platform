<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VplayAccount extends Model
{
    protected $fillable = [
        'vendor_info_id',
        'username',
        'player_id',
        'member_id',
        'balance',
        'admin'
    ];
}