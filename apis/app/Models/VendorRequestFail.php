<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $vendor_id 遊戲商系統編號
 * @property int $general_agent_id 總代理ID編號
 * @property int $member_id 會員系統編號
 * @property int $status 狀態，0:停止轉帳, 1: 設定維護, 2:恢復轉帳,
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read Member|null $member
 * @property-read Vendor|null $vendor
 *
 * @method static Builder<static>|VendorRequestFail newModelQuery()
 * @method static Builder<static>|VendorRequestFail newQuery()
 * @method static Builder<static>|VendorRequestFail query()
 *
 * @mixin \Eloquent
 */
class VendorRequestFail extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
