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
        'domain' => 'r_alpha_dash',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'template_id' => 'digits_between:0,10',
        'title' => 'between:0,60',
        'logo_pc' => 'base64image',
        'logo_mobile' => 'base64image',
        'icon' => 'base64image'
    ];
}