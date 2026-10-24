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
 * @property int $id
 * @property int $member_id 會員系統編號
 * @property int $vip_content_id VIP系統編號
 * @property numeric $deposit_amount 已充值金額
 * @property numeric $keep_amount 累積保級金額
 * @property string|null $birthday 生日
 * @property string|null $target_date 達標日期
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read VipContent|null $vipContent
 * @property-read Collection<int, VipContentReward> $vipContentReward
 * @property-read int|null $vip_content_reward_count
 *
 * @method static Builder<static>|MemberVip newModelQuery()
 * @method static Builder<static>|MemberVip newQuery()
 * @method static Builder<static>|MemberVip query()
 *
 * @mixin \Eloquent
 */
class MemberVip extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function vipContent(): BelongsTo
    {
        return $this->belongsTo(VipContent::class);
    }

    public function vipContentReward(): HasMany
    {
        return $this->hasMany(VipContentReward::class, 'vip_content_id', 'vip_content_id');
    }
}
