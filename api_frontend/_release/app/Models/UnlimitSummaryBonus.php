<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnlimitSummaryBonus extends Model
{
    //
    protected $table = 'unlimit_summary_bonus';
    
    protected $fillable = ['member_id', 'performance_amount', 'bonus_amount', 'withdraw_amount', 'admin'];
}
