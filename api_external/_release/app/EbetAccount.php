<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class EbetAccount extends Model
{

    protected $fillable = [
        'usename',
        'password',
        'balance',
        'member_id',
        'updated_at',
    ];
}