<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 積寶活動中獎通知紀錄
 *
 * @property int $id
 * @property int $member_id members.id
 * @property int $event_jackpot_id event_jackpots.id
 * @property int $prize_member_id prize_members.id
 * @property int $status 狀態 0:未通知,1:已通知
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read EventJackpot $jackpot
 * @property-read Member $member
 *
 * @method static Builder<static>|EventJackpotRecord newModelQuery()
 * @method static Builder<static>|EventJackpotRecord newQuery()
 * @method static Builder<static>|EventJackpotRecord query()
 *
 * @mixin \Eloquent
 */
class EventJackpotRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function jackpot(): BelongsTo
    {
        return $this->belongsTo(EventJackpot::class, 'event_jackpot_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
