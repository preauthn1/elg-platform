<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Analytics extends Model
{

    public $rules_search = [
        'agent_id' => 'required|digits_between:0,10'
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
