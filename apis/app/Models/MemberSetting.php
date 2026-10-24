<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 系統編號
 * @property int $member_id 帳號系統編號(User)
 * @property int $activity_limit_time 最長非活動狀態時間(minute)
 * @property int $push_notify 接受優惠通知-推送通知
 * @property int $sms_notify 接受優惠通知-短信提示
 * @property int $email_notify 接受優惠通知-電子郵件
 * @property int $message_box_notify 站內優惠通知-信息收件箱
 * @property int $message_popup_notify 站內優惠通知-優惠彈出信息
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|MemberSetting newModelQuery()
 * @method static Builder<static>|MemberSetting newQuery()
 * @method static Builder<static>|MemberSetting query()
 *
 * @mixin \Eloquent
 */
class MemberSetting extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'admin',
    ];

    // public $rules_create = [
    //     'activity_limit_time' => 'required|digits_between:0,10',
    //     'push_notify' => 'required|boolean',
    //     'sms_notify' => 'required|boolean',
    //     'email_notify' => 'required|boolean',
    //     'message_box_notify' => 'required|boolean',
    //     'message_popup_notify' => 'required|boolean'
    // ];
    //
    // public $rules_update = [
    //     'activity_limit_time' => 'digits_between:0,10',
    //     'push_notify' => 'boolean',
    //     'sms_notify' => 'boolean',
    //     'email_notify' => 'boolean',
    //     'message_box_notify' => 'boolean',
    //     'message_popup_notify' => 'boolean'
    // ];
    //
    // protected $fillable = [
    //     'member_id',
    //     'activity_limit_time',
    //     'push_notify',
    //     'sms_notify',
    //     'email_notify',
    //     'message_box_notify',
    //     'message_popup_notify',
    //     'created_at',
    //     'updated_at',
    //     'admin'
    // ];
}
