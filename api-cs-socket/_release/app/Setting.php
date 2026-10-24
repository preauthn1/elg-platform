<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{

    protected $fillable = [
        'agent_id',
        'name',
        'background_image',
        'background_color',
        'header_background_color',
        'header_font_color',
        'api_url',
        'admin'
    ];

    public $rules_save = [
        'name' => 'between:0,60',
        'background_image' => 'base64image',
        'background_color' => 'between:0,60',
        'header_background_color' => 'between:0,60',
        'header_font_color' => 'between:0,60',
        'api_url' => 'url|between:0,60'
    ];
}