<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $total_bet_record_id
 * @property numeric $win 贏分
 * @property int $agent_id 代理ID
 * @property int $vendor_id 遊戲商ID
 * @property int $member_id 玩家ID
 * @property int $game_id 遊戲ID
 * @property string $payout_time 發放時間
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property-read Game|null $game
 * @property-read Member|null $member
 * @property-read TotalBetRecord|null $totalBetRecord
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MarqueeWinPointRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MarqueeWinPointRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MarqueeWinPointRecord query()
 *
 * @mixin \Eloquent
 */
class MarqueeWinPointRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function totalBetRecord(): BelongsTo
    {
        return $this->belongsTo(TotalBetRecord::class);
    }
}
