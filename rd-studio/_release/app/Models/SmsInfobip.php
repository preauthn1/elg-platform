<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsInfobip extends Model
{

    protected $fillable = [
        'member_id',
        'mobile',
        'text',
        'smsCount',
        'message_id',
        'send_at',
        'done_at',
        'price',
        'currency',
        'status_group_id',
        'status_group_name',
        'status_id',
        'status_name',
        'status_description',
        'status'
    ];
}
