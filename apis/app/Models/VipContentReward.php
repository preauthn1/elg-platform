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
 * @property int $vip_content_id VIP系統編號
 * @property int $vip_reward_id VIP獎勵系統編號
 * @property int $type 分數類別;0:固定金額(point)、1:%數(point_percentage)、2:額外返水(member_vip_prizes.point)
 * @property numeric $max_point 金額上限
 * @property int $point_type 分數類別;1:平台點數、2:禮碼、3:實體獎勵
 * @property numeric $condition 條件
 * @property numeric $point 獎項金額
 * @property numeric $point_percentage 分數%數
 * @property int $expired_hour 幾小時候過期;0:無期限、-1:發獎當天後過期
 * @property int $flow_limit_multiple 稽核流水倍數
 * @property int $status 狀態 0:關閉 1:開啟
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Collection<int, MemberVipPrize> $memberVipPrizes
 * @property-read int|null $member_vip_prizes_count
 * @property-read VipContent|null $vipContent
 * @property-read VipReward|null $vipReward
 *
 * @method static Builder<static>|VipContentReward newModelQuery()
 * @method static Builder<static>|VipContentReward newQuery()
 * @method static Builder<static>|VipContentReward query()
 *
 * @mixin \Eloquent
 */
class VipContentReward extends Model
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

    public function vipReward(): BelongsTo
    {
        return $this->belongsTo(VipReward::class);
    }

    public function memberVipPrizes(): HasMany
    {
        return $this->hasMany(MemberVipPrize::class, 'vip_content_reward_id', 'id');
    }
}
