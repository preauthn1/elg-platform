<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Game extends Model
{

    protected $fillable = [
        'vendor_id',
        'type_id',
        'game_type',
        'name',
        'lottery_pool',
        'image',
        'is_new',
        'is_hot',
        'status',
        'admin'
    ];

    public $rules_search = [
        'vendor_id' => 'numeric',
        'type_id' => 'numeric',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_lottery = [
        'number' => 'numeric'
    ];
}
