<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class MemberSetting extends Model
{
    public $rules = [
        'language_id' => 'required|numeric',
        'timezone_id' => 'required|numeric',
        'activity_limit_time' => 'required|numeric',
        'push_notify' => 'required|boolean',
        'sms_notify' => 'required|boolean',
        'email_notify' => 'required|boolean',
        'message_box_notify' => 'required|boolean',
        'message_popup_notify' => 'required|boolean',
    ];

    protected $fillable = [
        'user_id', 'language_id', 'timezone_id', 'activity_limit_time', 'push_notify', 'sms_notify', 'email_notify', 'message_box_notify', 'message_popup_notify', 'created_at', 'updated_at', 'admin',
    ];
}
