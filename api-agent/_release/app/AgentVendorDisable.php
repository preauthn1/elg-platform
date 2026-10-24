<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class AgentVendorDisable extends Model
{

    protected $fillable = [
        'agent_id',
        'vendor_id',
    ];
}