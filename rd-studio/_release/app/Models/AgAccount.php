<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgAccount extends Model
{

    protected $fillable = [
        'vendor_info_id',
        'username',
        'balance',
        'password',
        'member_id',
        'admin'
    ];
}