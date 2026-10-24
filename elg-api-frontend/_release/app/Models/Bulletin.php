<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bulletin extends Model
{

    public $rules_search = [
        'type_id' => 'digits_between:0,10',
        'country_id' => 'digits_between:0,10',
        'agent_id' => 'digits_between:0,10',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    protected $fillable = [
        'type_id',
        'language_id',
        'country_id',
        'agent_id',
        'title',
        'content',
        'is_public',
        'start_time',
        'end_time',
        'admin'
    ];
}
