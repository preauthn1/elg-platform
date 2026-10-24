<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlowLimit extends Model
{
    public $rules_cancel = [

        'id' => 'required',
        'memo' => '',        
    ];

    protected $fillable = [
        'member_id',
        'type',
        'event_id',
        'reference_id',
        'flow_limit',
        'reach',
        'reamrk',
        'status',
        'admin'
    ];
}