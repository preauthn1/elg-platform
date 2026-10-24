<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameTypeName extends Model
{

    protected $fillable = [
        'language_id',
        'game_type_id',
        'name',
        'admin'
    ];
}
