<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CokeFightingAccount extends Model
{

    protected $fillable = [
        'usename',
        'balance',
        'member_id',
    ];
}