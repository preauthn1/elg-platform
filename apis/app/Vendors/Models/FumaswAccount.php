<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $vendor_info_id 商戶編號
 * @property string $username 玩家帳號
 * @property string $player_id 三方帳號
 * @property string $password 玩家密碼
 * @property int $member_id 會員系統編號
 * @property int $status 狀態 0:關閉,1:正常
 * @property int $admin 管理員編號
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FumaswAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FumaswAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FumaswAccount query()
 *
 * @mixin \Eloquent
 */
class FumaswAccount extends AbstractAccount
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
