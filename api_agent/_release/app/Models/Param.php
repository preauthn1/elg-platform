<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Param extends Model
{

    protected $fillable = [
        'type',
        'parameter',
        'value',
        'sort'
    ];

    public $rules_search = [
        'key' => 'required'
    ];
}
