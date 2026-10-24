<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberValidationType extends Model
{

    protected $fillable = [
        'name',
        'status',
        'admin'
    ];
}
