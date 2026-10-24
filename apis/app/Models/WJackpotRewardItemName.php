<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * W-Jackpot 獎勵項目-多語言
 *
 * @property int $id
 * @property int $language_id
 * @property int $w_jackpot_reward_item_id w_jackpot_reward_items.id
 * @property string $name
 * @property string|null $prize_name 獎項名稱
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read WJackpotRewardItem $rewardItem
 *
 * @method static Builder<static>|WJackpotRewardItemName newModelQuery()
 * @method static Builder<static>|WJackpotRewardItemName newQuery()
 * @method static Builder<static>|WJackpotRewardItemName query()
 *
 * @mixin \Eloquent
 */
class WJackpotRewardItemName extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function rewardItem(): BelongsTo
    {
        return $this->belongsTo(WJackpotRewardItem::class, 'w_jackpot_reward_item_id');
    }
}
