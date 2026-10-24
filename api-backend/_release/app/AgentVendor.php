<?php

namespace App;

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
    public $rules_search = [
        'vendor_id' => 'required|digits_between:0,10',
    ];

    public $rules_setting = [
        'vendor_id' => 'required|digits_between:0,10',
        'vendor_info_id' => 'required|digits_between:0,10',
        'status' => 'required|boolean'
    ];
}