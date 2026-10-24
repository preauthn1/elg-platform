<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberSetting extends Model
{

    public $rules_create = [
        'activity_limit_time' => 'required|digits_between:0,10',
        'push_notify' => 'required|boolean',
        'sms_notify' => 'required|boolean',
        'email_notify' => 'required|boolean',
        'message_box_notify' => 'required|boolean',
        'message_popup_notify' => 'required|boolean'
    ];

    public $rules_update = [
        'activity_limit_time' => 'digits_between:0,10',
        'push_notify' => 'boolean',
        'sms_notify' => 'boolean',
        'email_notify' => 'boolean',
        'message_box_notify' => 'boolean',
        'message_popup_notify' => 'boolean'
    ];

    protected $fillable = [
        'member_id',
        'activity_limit_time',
        'push_notify',
        'sms_notify',
        'email_notify',
        'message_box_notify',
        'message_popup_notify',
        'created_at',
        'updated_at',
        'admin'
    ];
}
