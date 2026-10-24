<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id event.id
 * @property int $member_id 玩家帳號
 * @property string $code 邀請碼
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read Member|null $event
 * @property-read Member|null $member
 *
 * @method static Builder<static>|EventCode newModelQuery()
 * @method static Builder<static>|EventCode newQuery()
 * @method static Builder<static>|EventCode query()
 *
 * @mixin \Eloquent
 */
class EventCode extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 關聯的活動
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id')
            ->where('status', 1);
    }

    /**
     * 關聯的 member 帳號
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id')
            ->where('status', 1);
    }
}
