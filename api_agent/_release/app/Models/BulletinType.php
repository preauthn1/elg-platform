<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BulletinType extends Model
{

    protected $fillable = [
        'name',
        'status',
        'admin'
    ];
}
