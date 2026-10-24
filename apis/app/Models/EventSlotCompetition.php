<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 老虎機爭霸賽期數
 *
 * @property int $id 流水號
 * @property int $event_id events.id
 * @property int $event_detail_id event_details.id
 * @property int $no 期數
 * @property string|null $draw_time 派發時間
 * @property string|null $remark 備註
 * @property int $status 狀態 0:未派獎, 1:已派獎, 2:派獎中
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read Event $event
 * @property-read EventDetail $eventDetail
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EventSlotCompetitionRecord> $records
 *
 * @method static Builder<static>|EventSlotCompetition newModelQuery()
 * @method static Builder<static>|EventSlotCompetition newQuery()
 * @method static Builder<static>|EventSlotCompetition query()
 *
 * @mixin \Eloquent
 */
class EventSlotCompetition extends Model
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

    public function eventDetail(): BelongsTo
    {
        return $this->belongsTo(EventDetail::class, 'event_detail_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(EventSlotCompetitionRecord::class, 'event_slot_competition_id');
    }
}
