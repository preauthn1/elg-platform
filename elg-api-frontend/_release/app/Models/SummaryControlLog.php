<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SummaryControlLog extends Model
{
    protected $fillable = [
    	'vendor_id', 
    	'stat_start_time', 
    	'stat_end_time',
    	'exec_start_time',
    	'exec_end_time'
    ];
}
