<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'ref_id',
        'bot_id',
        'chat_id',
        'message_id',
        'sender',
        'type',
        'action',
        'status',
        'content',
        'info',
        'extra_info',
    ];
}
