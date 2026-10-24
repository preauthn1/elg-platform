<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorMaintenance extends Model
{    
    
    protected $table = 'vendor_maintenances';     

    protected $guarded = [
        'id',
        'created_at',
        'updated_at'
    ];
    
    public $rules_show = [
        'id' => 'required',    
    ];

    public $rules_create = [
        'vendor_id' => 'required',
        'type' => 'required',
        'start_time' => 'required|date_format:Y-m-d H:i',
        'end_time' => 'required|date_format:Y-m-d H:i',
        'status' => 'required'      
    ];

    public $rules_search = [
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

}