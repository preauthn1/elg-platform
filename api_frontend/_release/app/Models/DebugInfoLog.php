<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DebugInfoLog extends Model
{
    protected $table = 'debug_info_logs';    

    protected $fillable = ['category', 'info_level', 'msg' , 'memo_1',  'memo_2' , 'number_value_1' , 'number_value_2' , 'user_ip' , 'user_identity'];

}