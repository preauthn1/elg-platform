<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceWhitelist extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at'
    ];

    public $rules_create = [
        'type' => 'required|in:1,2',
        'value' => 'required',
    ];

    public $rules_delete = [
        'id' => 'required|numeric',
    ];
}
