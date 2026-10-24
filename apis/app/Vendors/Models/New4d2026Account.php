<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property int $member_id 會員編號
 * @property string $username 玩家帳號
 * @property string $password 玩家密碼
 * @property int $status 狀態 0:關閉,1:正常
 * @property int $admin
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|New4d2026Account newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|New4d2026Account newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|New4d2026Account query()
 *
 * @mixin \Eloquent
 */
class New4d2026Account extends AbstractAccount
{
    protected $table = 'new4d2026_accounts';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
