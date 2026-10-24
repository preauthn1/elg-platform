<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Models\Member;
use App\Models\VendorInfo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property string $username 玩家帳號
 * @property int $member_id 會員系統編號
 * @property int $status 狀態 0:關閉,1:正常
 * @property int $admin 管理員編號
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read Member|null $member
 * @property-read VendorInfo|null $vendorInfo
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeswAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeswAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeswAccount query()
 *
 * @mixin \Eloquent
 */
class WeswAccount extends AbstractAccount
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function vendorInfo(): BelongsTo
    {
        return $this->belongsTo(VendorInfo::class, 'vendor_info_id');
    }
}
