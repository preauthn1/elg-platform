<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberAgent extends Model
{
    //
    protected $table = 'member_agent';
    
    
    protected $fillable = ['member_id', 'parent_id', 'topper_member_id', 'agent_mode', 'agent_level', 'agent_relation'];
}
