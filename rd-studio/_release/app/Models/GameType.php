<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameType extends Model
{

    protected $fillable = [
        'parent',
        'name',
        'status',
        'admin'
    ];
}
