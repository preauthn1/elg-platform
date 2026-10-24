<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PgsswAccount extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at'
    ];
}
