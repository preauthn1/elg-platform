<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class OrderType extends Model
{

    protected $fillable = [
        'name',
        'status',
        'created_at',
        'updated_at',
        'admin'
    ];
}
