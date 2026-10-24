<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_id 代理系統編號
 * @property int $level 代理系統編號
 * @property string|null $name VIP名稱
 * @property numeric $target_amount 所需充值金額
 * @property numeric $keep_amount 保級金額
 * @property int $withdrawal_times_limit 提現次數
 * @property string|null $image 圖片
 * @property numeric $withdrawal_amount_limit 當日提款上限
 * @property numeric $tax 稅收
 * @property int $vip_channel 支持VIP通道
 * @property int $exclusive_service 專屬服務經理
 * @property int $is_downgrade 是否會被降級
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Collection<int, MemberVip> $memberVip
 * @property-read int|null $member_vip_count
 * @property-read Collection<int, VipContentName> $vipContentNames
 * @property-read int|null $vip_content_names_count
 * @property-read Collection<int, VipContentReward> $vipContentRewards
 * @property-read int|null $vip_content_rewards_count
 * @property-read Collection<int, VipContentWithdrawalType> $vipContentWithdrawalTypes
 * @property-read int|null $vip_content_withdrawal_types_count
 * @property-read Collection<int, VipRebateProportion> $vipRebateProportions
 * @property-read int|null $vip_rebate_proportions_count
 * @property-read Collection<int, VipContentWithdrawalType> $withdrawalTypes
 * @property-read int|null $withdrawal_types_count
 *
 * @method static Builder<static>|VipContent newModelQuery()
 * @method static Builder<static>|VipContent newQuery()
 * @method static Builder<static>|VipContent query()
 *
 * @mixin \Eloquent
 */
class VipContent extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function memberVip(): HasMany
    {
        return $this->hasMany(MemberVip::class);
    }

    public function withdrawalTypes(): HasMany
    {
        return $this->hasMany(VipContentWithdrawalType::class);
    }

    public function vipContentNames()
    {
        return $this->hasMany(VipContentName::class);
    }

    public function vipContentRewards(): HasMany
    {
        return $this->hasMany(VipContentReward::class, 'vip_content_id', 'id');
    }

    public function vipContentWithdrawalTypes()
    {
        return $this->hasMany(VipContentWithdrawalType::class, 'vip_content_id', 'id');
    }

    public function vipRebateProportions()
    {
        return $this->hasMany(VipRebateProportion::class, 'vip_content_id', 'id');
    }
}
