<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class VendorMaintenance extends Model
{
    protected $fillable = [
        'vendor_id',
        'start_time',
        'end_time',
        'status'
    ];

    public $rules_search = [
        'vendor_id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'vendor_id' => 'required|digits_between:0,10',
        'start_time' => 'required|date_format:Y-m-d H:i:s',
        'end_time' => 'required|date_format:Y-m-d H:i:s|after:start_time',
        'status' => 'boolean'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'vendor_id' => 'digits_between:0,10',
        'start_time' => 'required|date_format:Y-m-d H:i:s',
        'end_time' => 'required|date_format:Y-m-d H:i:s|after:start_time',
        'status' => 'boolean'
    ];

    public $rules_delete = [
        'id' => 'required|digits_between:0,10'
    ];
}