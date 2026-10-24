<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $agent_id 營運商系統編號
 * @property int $vendor_id 遊戲商系統編號
 * @property int $vendor_info_id 遊戲商資訊編號
 * @property int $status 狀態
 * @property int $self_status 只影響自己層級的status開關
 * @property int $backend_show 後台顯示
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read Vendor|null $vendor
 * @property-read Collection<int, VendorCustomizedItem> $vendorCustomizedItems
 * @property-read int|null $vendor_customized_items_count
 * @property-read VendorInfo|null $vendorInfo
 *
 * @method static Builder<static>|AgentVendor newModelQuery()
 * @method static Builder<static>|AgentVendor newQuery()
 * @method static Builder<static>|AgentVendor query()
 *
 * @mixin \Eloquent
 */
class AgentVendor extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function vendorInfo(): BelongsTo
    {
        return $this->belongsTo(VendorInfo::class, 'vendor_info_id');
    }

    public function vendorCustomizedItems(): HasMany
    {
        return $this->hasMany(VendorCustomizedItem::class, 'vendor_id', 'vendor_id');
    }
}
