<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlowLimit extends Model
{
    protected $fillable = [
        'member_id',
        'type',
        'reference_id',
        'flow_limit',
        'reach',
        'status',
        'admin'
    ];
}
