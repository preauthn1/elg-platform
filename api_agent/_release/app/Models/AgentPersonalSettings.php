<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentPersonalSettings extends Model
{
    protected $table = 'agent_personal_settings';

    protected $fillable = ['agent_id', 'setting_id', 'sub_category', 'value'];       

}
