<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FavouriteGame extends Model
{

    protected $fillable = [
        'member_id',
        'game_id',
        'status',
        'admin'
    ];

    public $rules_set = [
        'game_id' => 'required|digits_between:0,10',
        'status' => 'required|boolean'
    ];
}
