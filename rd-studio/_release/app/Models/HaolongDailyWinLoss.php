<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HaolongDailyWinLoss extends Model
{

    protected $fillable = [
        'draw_date',
        'login_id',
        'turnover',
        'strike',
        'commission',
        'total',
        'admin'
    ];
}
