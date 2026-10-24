<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SummaryControlLog extends Model
{
    //資料表不存在 created_at and updated_at 欄位，設定忽略時間標記欄位
    public $timestamps = false;

    protected $guarded = [
        'id',
        'created_at'
    ];
}
