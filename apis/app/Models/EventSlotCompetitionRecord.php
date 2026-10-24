<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 老虎機爭霸賽玩家紀錄
 *
 * @property int $id 流水號
 * @property int $event_slot_competition_id event_slot_competitions.id
 * @property int $member_id members.id
 * @property numeric $bet_amount 投注金額
 * @property string $last_bet_time 最後投注時間
 * @property int $prize_content_id 獎項ID; prize_contents.id
 * @property int $ranking 名次
 * @property numeric $point 獎勵金額
 * @property int $type 中獎結果; 0:未中, 1:中獎
 * @property int $status 提領結果; 0:未領, 1:已領取, 2:領取中
 * @property string|null $time 領取時間
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read EventSlotCompetition $competition
 * @property-read Member $member
 *
 * @method static Builder<static>|EventSlotCompetitionRecord newModelQuery()
 * @method static Builder<static>|EventSlotCompetitionRecord newQuery()
 * @method static Builder<static>|EventSlotCompetitionRecord query()
 *
 * @mixin \Eloquent
 */
class EventSlotCompetitionRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function competition(): BelongsTo
    {
        return $this->belongsTo(EventSlotCompetition::class, 'event_slot_competition_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
