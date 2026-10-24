<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $vendor_info_id 商戶編號
 * @property string $username 玩家帳號（含 account_prefix，即三方 PlayerAccount／注單 UserName）
 * @property int $member_id 會員系統編號
 * @property int $admin 管理員編號
 * @property int $status 狀態 0:關閉,1:正常
 * @property \Illuminate\Support\Carbon|null $created_at 建立日期
 * @property \Illuminate\Support\Carbon|null $updated_at 更新日期
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SuncityAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SuncityAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SuncityAccount query()
 *
 * @mixin \Eloquent
 */
class SuncityAccount extends AbstractAccount
{
    protected $table = 'suncity_accounts';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
