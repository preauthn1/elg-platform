<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 刷分排行榜活動期數
 *
 * @property int $id
 * @property int $event_id events.id
 * @property int $event_detail_id event_details.id
 * @property int $no 期數
 * @property string|null $payout_time 派發時間(UTC+0)
 * @property int $status 狀態;0:未派發,1:已派發
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read Event $event
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EventRefreshRankingRecord> $records
 *
 * @method static Builder<static>|EventRefreshRanking newModelQuery()
 * @method static Builder<static>|EventRefreshRanking newQuery()
 * @method static Builder<static>|EventRefreshRanking query()
 *
 * @mixin \Eloquent
 */
class EventRefreshRanking extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(EventRefreshRankingRecord::class, 'event_refresh_ranking_id');
    }
}
