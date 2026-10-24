<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class AgentValidationItem extends Model
{

    public $rules = [
        'type_id' => 'required|digits_between:0,10',
        'status' => 'required|boolean'
    ];

    protected $fillable = [
        'agent_id',
        'validation_type_id',
        'status',
        'admin'
    ];
}
