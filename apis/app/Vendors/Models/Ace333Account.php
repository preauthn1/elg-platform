<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id 流水號
 * @property int $vendor_info_id 遊戲商資訊編號
 * @property string $username ace333帳號
 * @property string|null $password 密碼
 * @property string|null $player_id ace333玩家編號
 * @property numeric $balance ace333餘額
 * @property int $member_id 會員系統編號
 * @property int $status 狀態 0:關閉,1:正常
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ace333Account newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ace333Account newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ace333Account query()
 *
 * @mixin \Eloquent
 */
class Ace333Account extends AbstractAccount
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'player_id';
}
