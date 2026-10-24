<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentOperateLogs extends Model
{
    protected $table = 'agent_operate_logs';

    protected $fillable = 
    [
        'agent_id', 
        'type', 
        'reference_id', 
        'debug_info', 
        'ip',
    ];
    
}