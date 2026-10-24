<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsSmsWork extends Model
{

    protected $fillable = [
        'member_id',
        'mobile',
        'text',
        'status',
        'transation_id',
        'response_message',
    ];
}