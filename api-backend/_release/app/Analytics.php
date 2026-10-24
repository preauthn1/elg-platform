<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class Analytics extends Model
{

    public $rules_search = [
        'status' => 'boolean'
    ];

    public $rules_update = [
        'type' => 'required|digits_between:0,10',
        'code' => 'required|between:0,300',
        'status' => 'required|boolean'
    ];

    protected $fillable = [
        'agent_id',
        'type',
        'code',
        'status',
        'created_at',
        'updated_at',
        'admin'
    ];
}
