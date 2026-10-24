<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameName extends Model
{

    protected $fillable = [
        'language_id',
        'game_id',
        'name',
        'admin'
    ];
}
