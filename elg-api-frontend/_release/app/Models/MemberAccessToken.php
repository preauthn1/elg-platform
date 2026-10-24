<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberAccessToken extends Model
{

    protected $fillable = [
        'member_id',
        'token',
        'expire_date',
        'admin'
    ];
}
