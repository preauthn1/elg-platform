<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberCode extends Model
{
    //
    
    
    protected $fillable = ['agent_id', 'member_id', 'agent_code'];
}
