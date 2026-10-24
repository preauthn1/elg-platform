<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JokerAccount extends Model
{

    protected $fillable = [
        'vendor_info_id',
        'username',
        'member_id',
        'admin'
    ];
}