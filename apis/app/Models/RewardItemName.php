<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 獎勵項目-多語言
 *
 * @property int $id
 * @property int $language_id
 * @property int $type_id reward_items.id
 * @property string $name
 * @property string $prize_name 獎項名稱
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read RewardItem $rewardItem
 *
 * @method static Builder<static>|RewardItemName newModelQuery()
 * @method static Builder<static>|RewardItemName newQuery()
 * @method static Builder<static>|RewardItemName query()
 *
 * @mixin \Eloquent
 */
class RewardItemName extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function rewardItem(): BelongsTo
    {
        return $this->belongsTo(RewardItem::class, 'type_id');
    }
}
