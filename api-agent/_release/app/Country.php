<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class Country extends Model
{

    protected $fillable = [
        'code',
        'name',
        'status',
        'admin'
    ];
}
