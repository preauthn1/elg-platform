<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderType extends Model
{

    protected $fillable = [
        'cash_flow_id',
        'name',
        'key',
        'status',
        'created_at',
        'updated_at',
        'admin'
    ];
}
