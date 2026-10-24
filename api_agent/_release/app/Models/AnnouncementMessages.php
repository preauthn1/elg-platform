<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnnouncementMessages extends Model
{
    protected $table = 'announcement_messages';

    protected $fillable = 
    [        
        'title', 
        'message', 
        'memo', 
        'organization_id', 
        'created_by',
        'updated_by' , 
        'status',
        'start_time',
        'end_time'
    ];    


    public $rules_create_and_participants = [
    	'title' => 'string|min:0|max:50',
    	'message' => 'required|string|min:1|max:1000',
        'memo' => 'string|min:0|max:50',
        'type' => 'required|min:1',
        'start_time' => 'required|date_format:Y-m-d H:i',
        'end_time' => 'required|date_format:Y-m-d H:i',
    ];   

    public $rules_typeOf_organization_id = [
        'organization_id' => 'required|min:1',
    ];   

    public $rules_typeOf_hierarchy_agent_id = [
        'hierarchy_agent_id' => 'required|min:1',
    ];       

}