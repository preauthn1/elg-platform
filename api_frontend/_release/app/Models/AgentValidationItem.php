<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentValidationItem extends Model
{

    protected $fillable = [
        'agent_id',
        'validation_type_id',
        'status',
        'admin'
    ];
}
