<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnnouncementMessagesReads extends Model
{
    protected $table = 'announcement_messages_reads';        

    protected $fillable = ['announcement_message_id', 'member_id'];
}