<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionLog extends Model
{
    protected $guarded = [
        'id'
    ];

    public $rules_search = [
        'vendor_id' => 'digits_between:0,10',
        'start_time' => 'date_format:Y-m-d H:i:s',
        'end_time' => 'date_format:Y-m-d H:i:s',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];
}