<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CokeFightingAccount extends Model
{
    protected $fillable = [
        'vendor_info_id',
        'username',
        'password',
        'balance',
        'member_id',
        'admin'
    ];
}