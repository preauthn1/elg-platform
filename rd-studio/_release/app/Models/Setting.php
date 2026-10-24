<?php

namespace App\Models;

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
}