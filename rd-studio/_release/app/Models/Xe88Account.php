<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Xe88Account extends Model
{

    protected $fillable = [
        'vendor_info_id',
        'username',
        'admin'
    ];
}