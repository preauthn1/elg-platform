<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentDomain extends Model
{

    protected $fillable = [
        'agent_id',
        'domain',
        'template_id',
        'logo_pc',
        'logo_mobile',
        'title',
        'status',
        'created_at',
        'updated_at',
        'admin'
    ];

    public $rules_search = [
        'domain' => 'required|domain'
    ];
}
