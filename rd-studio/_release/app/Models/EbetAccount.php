<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EbetAccount extends Model
{

    protected $fillable = [
        'vendor_info_id',
        'username',
        'password',
        'member_id',
        'admin'
    ];
}