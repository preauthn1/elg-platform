<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberInputData extends Model
{

    protected $fillable = [
        'member_id',
        'input_id',
        'value',
        'admin'
    ];
}
