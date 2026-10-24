<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentDomain extends Model
{
    protected $guarded = [
        'id'
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
        'id' => 'digits_between:0,10',
        'template_id' => 'digits_between:0,10',
        'title' => 'between:0,60'
    ];
}