<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Models\Member;
use App\Models\VendorInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property string $username 玩家帳號
 * @property int $member_id 會員系統編號
 * @property int $status 狀態 0:關閉,1:正常
 * @property int $admin 管理員編號
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read Member|null $member
 * @property-read VendorInfo|null $vendorInfo
 *
 * @method static Builder<static>|MotivationswAccount newModelQuery()
 * @method static Builder<static>|MotivationswAccount newQuery()
 * @method static Builder<static>|MotivationswAccount query()
 *
 * @mixin \Eloquent
 */
class MotivationswAccount extends AbstractAccount
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
