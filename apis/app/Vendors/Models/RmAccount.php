<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property string $username 玩家帳號
 * @property numeric $balance 玩家餘額
 * @property int $member_id 會員系統編號
 * @property int $status 狀態 0:關閉,1:正常
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RmAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RmAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RmAccount query()
 *
 * @mixin \Eloquent
 */
class RmAccount extends AbstractAccount
{
    protected ?string $vendorUsernameColumn = 'username';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
