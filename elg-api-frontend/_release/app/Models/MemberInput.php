<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberInput extends Model
{

    protected $fillable = [
        'agent_id',
        'type_id',
        'title',
        'value',
        'admin'
    ];
}
