<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class AgentLoginLog extends Model
{
    protected $fillable = ['agent_id', 'ip', 'status', 'admin'];
}
