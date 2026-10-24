<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property string $username 玩家帳號（DST Play user_id）
 * @property int $member_id 會員系統編號
 * @property string|null $session_token 遊戲啟動時產生的會話令牌，供 getAuth 回調查找玩家
 * @property int $admin 管理員編號
 * @property int $status 狀態 0:關閉,1:正常
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static> newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 *
 * @mixin \Eloquent
 */
class DstPlaySwAccount extends AbstractAccount
{
    protected $table = 'dstplaysw_accounts';

    protected ?string $vendorUsernameColumn = 'username';
}
