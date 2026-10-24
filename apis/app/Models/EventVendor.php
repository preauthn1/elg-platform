<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 代理層級的活動遊戲商設定（刷分排行榜活動用）
 *
 * @property int $id
 * @property int $agent_id agents.id
 * @property int $vendor_id vendors.id
 * @property int $event_vendor_info_id event_vendor_infos.id
 * @property int $status 狀態0:停用,1:啟用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 管理員編號
 * @property-read EventVendorInfo $eventVendorInfo
 * @property-read Vendor $vendor
 *
 * @method static Builder<static>|EventVendor newModelQuery()
 * @method static Builder<static>|EventVendor newQuery()
 * @method static Builder<static>|EventVendor query()
 *
 * @mixin \Eloquent
 */
class EventVendor extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function eventVendorInfo(): BelongsTo
    {
        return $this->belongsTo(EventVendorInfo::class, 'event_vendor_info_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
