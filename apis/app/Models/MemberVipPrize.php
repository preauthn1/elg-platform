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
 * @property int $vip_content_reward_id VIP獎勵系統編號
 * @property int $point_type 1:真金 2:禮碼
 * @property numeric $base_amount 基數(用來乘上獎勵%數)
 * @property numeric $point 獎勵金額
 * @property string|null $expired_time 過期時間
 * @property int $status 狀態 0:未領取 1:已領取
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read VipContentReward|null $vipContentReward
 *
 * @method static Builder<static>|MemberVipPrize newModelQuery()
 * @method static Builder<static>|MemberVipPrize newQuery()
 * @method static Builder<static>|MemberVipPrize query()
 *
 * @mixin \Eloquent
 */
class MemberVipPrize extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function vipContentReward(): BelongsTo
    {
        return $this->belongsTo(VipContentReward::class, 'vip_content_reward_id');
    }
}
