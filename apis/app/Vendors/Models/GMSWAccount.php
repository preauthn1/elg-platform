<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $vendor_info_id 商戶編號
 * @property string $username 玩家帳號
 * @property int $member_id 會員系統編號
 * @property string|null $session_token
 * @property int $admin 管理員編號
 * @property int $status 狀態 0:關閉,1:正常
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GMSWAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GMSWAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GMSWAccount query()
 *
 * @mixin \Eloquent
 */
class GMSWAccount extends AbstractAccount
{
    protected $table = 'gmsw_accounts';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
