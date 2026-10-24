<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class AgentTemplate extends Model
{

    protected $fillable = [
        'agent_id',
        'template_id',
        'status',
        'created_at',
        'updated_at',
        'admin'
    ];

    public $rules_search = [
        'agent_id' => 'required|digits_between:0,10',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules = [
        'agent_id' => 'required|digits_between:0,10',
        'template_id' => 'required|digits_between:0,10',
        'status' => 'required|boolean'
    ];
}
