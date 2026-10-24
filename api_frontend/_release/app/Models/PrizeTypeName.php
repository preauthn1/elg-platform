<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrizeTypeName extends Model
{

    protected $fillable = [
        'language_id',
        'type_id',
        'name',
        'admin'
    ];
}
