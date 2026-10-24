<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{

    public $rules_search = [
        'agent_id' => 'digits_between:0,10',
        'status' => 'boolean'
    ];

    public $rules_setting = [
        'agent_id' => 'digits_between:0,10',
        'vendor_id' => 'required|digits_between:0,10',
        'backend_show' => 'boolean',
        'status' => 'boolean'
    ];

    protected $fillable = [
        'name',
        'rate',
        'percentage',
        'status'
    ];
}