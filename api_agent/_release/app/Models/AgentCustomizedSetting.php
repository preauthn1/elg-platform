<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentCustomizedSetting extends Model
{
    protected $table = 'agent_customized_settings';

    protected $fillable = ['agent_group_id', 'setting_key', 'sub_category', 'value' , 'memo'];       
}
