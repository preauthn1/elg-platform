<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentSettingDefine extends Model
{
    protected $table = 'agent_setting_defines';

    
    public $rules_create = [
        'setting_id' => 'required|digits_between:1,9999999',
        'parent' => 'required|digits_between:0,9999999',
        'type' => 'required|digits_between:1,9999',
        'language_text_code' => 'required|string|max:500'
    ];


    protected $fillable = [

        'setting_id', 
        'parent', 
        'type', 
        'is_visible', 
        'memo', 
        'language_text_code', 
        'default_value', 
        'data_type', 
        'is_data_required'

    ];       

}
