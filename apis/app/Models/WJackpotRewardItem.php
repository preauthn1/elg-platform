<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * W-Jackpot 獎勵項目
 *
 * @property int $id
 * @property int $prize_content_id
 * @property string $name
 * @property int $level
 * @property int|null $type 獎項類型; 1: 獎金, 2:獎品
 * @property numeric $bonus
 * @property string|null $prize_name 獎項名稱
 * @property string|null $memo
 * @property bool|null $status 狀態; 1:啟用, 0:停用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int|null $admin
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WJackpotRewardItemName> $rewardItemNames
 *
 * @method static Builder<static>|WJackpotRewardItem newModelQuery()
 * @method static Builder<static>|WJackpotRewardItem newQuery()
 * @method static Builder<static>|WJackpotRewardItem query()
 *
 * @mixin \Eloquent
 */
class WJackpotRewardItem extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function rewardItemNames(): HasMany
    {
        return $this->hasMany(WJackpotRewardItemName::class, 'w_jackpot_reward_item_id');
    }
}
