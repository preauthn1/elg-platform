<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgAccount extends Model
{
    protected $guarded = [
        'id',
        'password',
        'vendor_id',
        'created_at',
        'updated_at'
    ];
}