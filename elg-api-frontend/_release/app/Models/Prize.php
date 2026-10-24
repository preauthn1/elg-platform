<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prize extends Model
{

    public $rules_show = [
        'event_id' => 'required|digits_between:0,10'
    ];

    protected $fillable = [
        'event_id',
        'type_id',
        'name',
        'background',
        'number',
        'is_repeatedly',
        'expire_date',
        'draw_date',
        'status',
        'admin'
    ];
}