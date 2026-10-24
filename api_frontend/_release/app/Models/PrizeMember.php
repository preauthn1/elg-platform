<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrizeMember extends Model
{

    protected $fillable = [
        'member_id',
        'prize_id',
        'content_id',
        'lottery_number',
        'lottery_point',
        'status',
        'admin'
    ];

    public $rules_search = [
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];
}
