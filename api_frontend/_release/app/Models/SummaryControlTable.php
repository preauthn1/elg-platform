<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SummaryControlTable extends Model
{
    protected $fillable = [
    	'vendor_id', 
    	'vendor_code', 
    	'stat_start_time', 
    	'stat_end_time',
    	'exec_start_time',
    	'exec_end_time'
    ];
}
