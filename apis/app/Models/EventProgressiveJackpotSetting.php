<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $level 彩金等級
 * @property numeric $deposit_point 須累積儲值的金額(首儲是0)
 * @property int $reward_type 獎勵類型 1:固定金額, 2:百分比
 * @property numeric $reward 彩金金額(reward_type=1)
 * @property numeric $reward_percentage 金額倍數(reward_type=2)，200%存2.0，計算時直接乘不用再除100
 * @property numeric $max_reward 金額上限(reward_type=2)
 * @property int $expire_day 多久後過期(天)
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $admin
 *
 * @method static Builder<static>|EventProgressiveJackpotSetting newModelQuery()
 * @method static Builder<static>|EventProgressiveJackpotSetting newQuery()
 * @method static Builder<static>|EventProgressiveJackpotSetting query()
 *
 * @mixin \Eloquent
 */
class EventProgressiveJackpotSetting extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
