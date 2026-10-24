<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorHourRecord extends Model
{
    protected $fillable = [
        'date_hour',
        'member_id',
        'vendor_id',
        'bet',
        'win',
    ];
}