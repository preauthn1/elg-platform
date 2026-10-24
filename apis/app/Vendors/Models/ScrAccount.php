<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id 流水號
 * @property int $vendor_info_id 遊戲商資訊編號
 * @property string $username scr帳號
 * @property string|null $player_id 918玩家帳號
 * @property string|null $password 密碼
 * @property numeric $balance scr餘額
 * @property int $vendor_id 營運商編號
 * @property int $member_id 會員帳號
 * @property int $status 狀態 0:關閉,1:正常
 * @property string|null $remark 備註
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScrAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScrAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScrAccount query()
 *
 * @mixin \Eloquent
 */
class ScrAccount extends AbstractAccount
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'player_id';
}
