<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberLoginLog extends Model
{
    protected $fillable = [
        'member_id',
        'ip',
        'status',
        'admin'
    ];
}
