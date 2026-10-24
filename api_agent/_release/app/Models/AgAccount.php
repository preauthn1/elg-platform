<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgAccount extends Model
{

    protected $fillable = [
        'username',
        'balance',
        'password',
        'member_id',
        'created_at',
        'updated_at',
        'admin'
    ];
}
