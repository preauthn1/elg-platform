<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SummaryEventRecord extends Model
{
    protected $primaryKey = ['summary_time', 'member_id', 'event_id'];
    public $incrementing = false;

    protected $guarded = [
        'created_at',
        'updated_at'
    ];
}
