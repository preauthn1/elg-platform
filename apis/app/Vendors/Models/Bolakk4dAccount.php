<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property string $username 玩家帳號
 * @property int $member_id 會員系統編號
 * @property int $status 狀態 0:關閉,1:正常
 * @property string $vendor_username 遊戲商的玩家帳號名
 * @property string $vendor_uid 遊戲商的玩家 UID-依據遊戲商需求
 * @property string $password 玩家密碼-依據遊戲商需求
 * @property string $session_token 玩家登入用 Token-依據遊戲商需求
 * @property int $admin 數據操作人員的管理者系統編號
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bolakk4dAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bolakk4dAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bolakk4dAccount query()
 *
 * @mixin \Eloquent
 */
class Bolakk4dAccount extends AbstractAccount
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'vendor_username';
}
