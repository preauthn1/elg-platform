<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class AgentDomain extends Model
{

    protected $fillable = [
        'agent_id',
        'domain',
        'template_id',
        'logo_pc',
        'logo_mobile',
        'icon',
        'title',
        'status',
        'created_at',
        'updated_at',
        'admin'
    ];

    public $rules_search = [
        'agent_id' => 'digits_between:0,10',
        'domain' => 'alpha_dash',
        'template_id' => 'digits_between:0,10',
        'status' => 'boolean',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_create = [
        'agent_id' => 'required|digits_between:0,10',
        'domain' => 'required|domain|unique:agent_domains',
        'status' => 'boolean'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'agent_id' => 'digits_between:0,10',
        'domain' => 'domain|unique:agent_domains',
        'template_id' => 'digits_between:0,10',
        'status' => 'boolean'
    ];

    public $rules_delete = [
        'id' => 'required|digits_between:0,10'
    ];
}