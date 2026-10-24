<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id 會員系統編號
 * @property numeric $first_deposit 首儲金額
 * @property numeric $total_deposit_point 累積儲值金額
 * @property int $block_ip_member_id IP重複的會員系統編號>0則狀態改為鎖定中
 * @property int $block_mobile_member_id 手機號重複的會員系統編號>0則狀態改為鎖定中
 * @property int $block_realname_member_id 真實姓名重複的會員系統編號>0則狀態改為鎖定中
 * @property bool $is_receive 是否已領取過獎金，用於判斷是否為第一次領取(第一次領取需重新檢查一次重複資訊)
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $admin
 * @property-read Member|null $member
 *
 * @method static Builder<static>|EventProgressiveJackpotUserDetail newModelQuery()
 * @method static Builder<static>|EventProgressiveJackpotUserDetail newQuery()
 * @method static Builder<static>|EventProgressiveJackpotUserDetail query()
 *
 * @mixin \Eloquent
 */
class EventProgressiveJackpotUserDetail extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_receive' => 'boolean',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
