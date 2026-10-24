<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class PrizeContent extends Model
{

    protected $fillable = [
        'prize_id',
        'name',
        'number',
        'percentage',
        'point',
        'status',
        'admin'
    ];
}
