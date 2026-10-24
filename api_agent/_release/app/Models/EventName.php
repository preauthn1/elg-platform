<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventName extends Model
{

    protected $fillable = [
        'language_id',
        'event_id',
        'title',
        'content',
        'admin'
    ];
}
