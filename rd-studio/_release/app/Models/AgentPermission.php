<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentPermission extends Model
{

    protected $fillable = [
        'agent_id',
        'agent_menu_id',
        'action',
        'admin'
    ];
}