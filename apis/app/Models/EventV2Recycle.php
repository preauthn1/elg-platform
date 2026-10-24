<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $event_v2_user_join_id event_v2_user_join_id
 * @property numeric|null $final_balance 最終餘額
 * @property numeric|null $withdraw 活動派獎金額
 * @property numeric|null $recycle_amount 回收金額
 * @property int $status 1:系統排程回收2:完成活動回收3:放棄活動回收4:系統重置回收
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read EventV2UserJoin|null $eventV2UserJoin
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2Recycle newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2Recycle newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2Recycle query()
 *
 * @mixin \Eloquent
 */
class EventV2Recycle extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function eventV2UserJoin(): BelongsTo
    {
        return $this->belongsTo(EventV2UserJoin::class);
    }
}
