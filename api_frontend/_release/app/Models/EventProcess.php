<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventProcess extends Model
{
    protected $fillable = [
        'detail_id',
        'member_id',
        'point',
        'received',
        'status'
    ];
}