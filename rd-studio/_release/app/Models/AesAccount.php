<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AesAccount extends Model
{

    protected $fillable = [
        'vendor_info_id',
        'username',
        'balance',
        'member_id',
        'admin'
    ];
}