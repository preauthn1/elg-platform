<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at'
    ];

    public $rules_search = [
        'vendor_id' => 'digits_between:0,10',
        'start_time' => 'date_format:Y-m-d H:i:s',
        'end_time' => 'date_format:Y-m-d H:i:s',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_red_envelope = [
        'type' => 'required|in:1,2,3,4',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];
}