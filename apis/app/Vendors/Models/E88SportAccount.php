<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $vendor_info_id 商戶編號
 * @property string $username 玩家帳號
 * @property int $member_id 會員系統編號
 * @property int $admin 管理員編號
 * @property int $status 狀態 0:關閉,1:正常
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|E88SportAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|E88SportAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|E88SportAccount query()
 *
 * @mixin \Eloquent
 */
class E88SportAccount extends AbstractAccount
{
    protected $table = 'e88sport_accounts';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
