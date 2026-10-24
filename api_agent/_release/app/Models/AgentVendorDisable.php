<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentVendorDisable extends Model
{

    protected $fillable = [
        'agent_id',
        'vendor_id',
    ];
}