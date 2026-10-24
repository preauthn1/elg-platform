<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnnouncementMessagesParticipants extends Model
{
    protected $table = 'announcement_messages_participants';

    protected $fillable = ['announcement_message_id', 'type', 'chat_group_id', 'hierarchy_agent_id', 'single_agent_id', 'member_id' , 'organization_id'];    

   
}