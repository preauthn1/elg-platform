<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberInputType extends Model
{

    protected $fillable = [
        'type',
        'code',
        'status',
        'admin'
    ];
}
