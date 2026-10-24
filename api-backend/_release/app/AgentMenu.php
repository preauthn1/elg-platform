<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class AgentMenu extends Model
{

    public $rules_search = [
        'agent_id' => 'digits_between:0,10'
    ];

    protected $fillable = [
        'code',
        'name',
        'parent',
        'url',
        'symbol',
        'action',
        'admin'
    ];
}
