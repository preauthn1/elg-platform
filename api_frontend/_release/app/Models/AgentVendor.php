<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentVendor extends Model
{

    protected $fillable = [
        'agent_id',
        'vendor_id',
        'vendor_info_id',
        'status',
        'admin'
    ];
}