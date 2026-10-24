<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentGroup extends Model
{
    protected $table = 'agent_groups';

    protected $fillable = ['agent_group_type', 'organization_id', 'name', 'memo'];     

    public $rules_create = [
        'agent_group_type' => 'required',
        'organization_id' => 'required',
        'name' => 'required|string|max:50',
        'memo' => 'string|max:50',
    ];

    public $rules_update = [
        'id' => 'required',
        'name' => 'required|string|max:50',
        'memo' => 'string|max:50',
    ];

    public $rules_copy = [
        'target_group_id' => 'required|min:1',
    ];

}
