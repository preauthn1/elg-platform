<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * W-Jackpot 抽獎券
 *
 * @property int $id
 * @property int $w_jackpot_id w_jackpots.id
 * @property int $member_id 玩家ID
 * @property string $ticket_number 抽獎券號碼
 * @property int $reward_item_id 獎項ID
 * @property string|null $reward_item_ids 獎項ID（多筆）
 * @property array|null $info
 * @property numeric|null $point 獎勵金額
 * @property int $type 中獎結果; 0:未中, 1:中獎, 2:派發中
 * @property int $status 提領結果; 0:未領, 1:已領取
 * @property string|null $time 領取時間
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read WJackpot $wJackpot
 * @property-read WJackpotRewardItem $rewardItem
 *
 * @method static Builder<static>|WJackpotTicket newModelQuery()
 * @method static Builder<static>|WJackpotTicket newQuery()
 * @method static Builder<static>|WJackpotTicket query()
 *
 * @mixin \Eloquent
 */
class WJackpotTicket extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'info' => 'array',
        ];
    }

    public function wJackpot(): BelongsTo
    {
        return $this->belongsTo(WJackpot::class, 'w_jackpot_id');
    }

    public function rewardItem(): BelongsTo
    {
        return $this->belongsTo(WJackpotRewardItem::class, 'reward_item_id');
    }
}
