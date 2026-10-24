<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorRequestFail extends Model
{

    protected $fillable = [
        'vendor_id',
        'status'
    ];
}