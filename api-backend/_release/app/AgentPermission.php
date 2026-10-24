<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class AgentPermission extends Model
{

    public $rules_update = [
        'agent_id' => 'required|digits_between:0,10',
        'menus' => 'required|array'
    ];

    protected $fillable = [
        'agent_id',
        'agent_menu_id',
        'action',
        'admin'
    ];
}
