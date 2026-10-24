<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsSmsGet extends Model
{

    protected $fillable = [
        'member_id',
        'mobile',
        'text',
        'stats',
        'error_code',
        'error_msg',
    ];
}