<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class Bulletin extends Model
{

    public $rules_search = [
        'type_id' => 'digits_between:0,10',
        'language_id' => 'digits_between:0,10',
        'country_id' => 'digits_between:0,10',
        'agent_id' => 'digits_between:0,10',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'type_id' => 'required|digits_between:0,10',
        'language_id' => 'required|digits_between:0,10',
        'country_id' => 'digits_between:0,10',
        'agent_id' => 'digits_between:0,10',
        'title' => 'required|between:0,30',
        'content' => 'required',
        'start_time' => 'required|date',
        'end_time' => 'required|date|after:start_time'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'type_id' => 'digits_between:0,10',
        'language_id' => 'digits_between:0,10',
        'country_id' => 'digits_between:0,10',
        'agent_id' => 'digits_between:0,10',
        'title' => 'between:0,30',
        'start_time' => 'date',
        'end_time' => 'date|after:start_time'
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
