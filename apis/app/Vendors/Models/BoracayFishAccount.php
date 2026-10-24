<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $vendor_info_id 商戶編號
 * @property int $member_id 會員系統編號
 * @property int $status 狀態 0:關閉,1:正常
 * @property string $username 玩家帳號
 * @property string $password 密碼
 * @property int $admin 管理員編號
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BoracayFishAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BoracayFishAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BoracayFishAccount query()
 *
 * @mixin \Eloquent
 */
class BoracayFishAccount extends AbstractAccount
{
    protected $table = 'boracayfish_accounts';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
