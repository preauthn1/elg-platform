<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\HasMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $event_v2_detail_id event_v2_details.id
 * @property int $member_id members.id
 * @property int|null $last_event_v2_reject_record_id 最新一筆拒絕紀錄id
 * @property int|null $event_v2_user_join_id event_v2_userjoins.id
 * @property string|null $allowed_time 允許參加時間
 * @property int $status 狀態
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read EventV2Detail|null $eventV2Detail
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EventV2RejectRecord> $eventV2RejectRecord
 * @property-read int|null $event_v2_reject_record_count
 * @property-read EventV2RejectRecord|null $lastEventV2RejectRecord
 * @property-read Member|null $member
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2RejectJoin newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2RejectJoin newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2RejectJoin query()
 *
 * @mixin \Eloquent
 */
class EventV2RejectJoin extends Model
{
    use HasMember;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function eventV2Detail(): BelongsTo
    {
        return $this->belongsTo(EventV2Detail::class);
    }

    public function eventV2RejectRecord(): HasMany
    {
        return $this->hasMany(EventV2RejectRecord::class);
    }

    public function lastEventV2RejectRecord(): HasOne
    {
        return $this->hasOne(EventV2RejectRecord::class)->latestOfMany();
    }
}
