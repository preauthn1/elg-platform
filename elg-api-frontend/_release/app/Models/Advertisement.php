<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Advertisement extends Model
{
    public $rules_search = [
        'path_id' => 'required|digits_between:0,10'
    ];

    protected $guarded = [
        'id',
        'created_at',
        'updated_at'
    ];
}