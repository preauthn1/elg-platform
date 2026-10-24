<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 抽獎券
 *
 * @property int $id 流水號
 * @property int $lucky_draw_id lucky_draws.id
 * @property int $member_id 玩家ID
 * @property string $ticket_number 抽獎券號碼
 * @property int $reward_item_id 獎項ID
 * @property numeric|null $point 獎勵金額
 * @property int $type 中獎結果; 0:未中 , 1:中獎
 * @property int $status 提領結果; 0:未領 , 1:已領取
 * @property string|null $time 領取時間
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read LuckyDraw $luckyDraw
 * @property-read RewardItem $rewardItem
 *
 * @method static Builder<static>|LuckyDrawTicket newModelQuery()
 * @method static Builder<static>|LuckyDrawTicket newQuery()
 * @method static Builder<static>|LuckyDrawTicket query()
 *
 * @mixin \Eloquent
 */
class LuckyDrawTicket extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function luckyDraw(): BelongsTo
    {
        return $this->belongsTo(LuckyDraw::class, 'lucky_draw_id');
    }

    public function rewardItem(): BelongsTo
    {
        return $this->belongsTo(RewardItem::class, 'reward_item_id');
    }
}
