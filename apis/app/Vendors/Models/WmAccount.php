<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id 流水號
 * @property int $vendor_info_id 遊戲商設定編號
 * @property string $username 玩家帳號帳號
 * @property string|null $user_id 避免同一個USERNAME重覆建立的編號(如果需要创建两次, 回应将会是相同, 但 WAPI 仅在第一次使用时生成新玩家。)
 * @property string $player_id 使用者授權碼
 * @property numeric $balance 玩家餘額
 * @property int $member_id 會員系統編號
 * @property int $status 狀態 0:關閉,1:正常
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WmAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WmAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WmAccount query()
 *
 * @mixin \Eloquent
 */
class WmAccount extends AbstractAccount
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'user_id';
}
