<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TotalBetRecord extends Model
{
    protected $fillable = [
    	'summary_time', 
    	'member_id', 
    	'vendor_id',
    	'game_id',
    	'reference_no',
    	'bet',
    	'valid_bet',
    	'win_lose',
    	'bet_time',
    	'payout_time',
    	'admin'
    ];
}
