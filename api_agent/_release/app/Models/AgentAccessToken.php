<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentAccessToken extends Model
{
    protected $fillable = ['agent_id', 'token', 'expire_date', 'admin'];
}
