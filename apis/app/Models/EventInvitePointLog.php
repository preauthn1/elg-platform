<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $summary_start_time 統計時間，用來避免重複新增
 * @property int $type 類型 1:真實姓名, 2:儲值, 3:投注流水, 4:領獎
 * @property int $member_id 會員系統編號
 * @property numeric $before_bet 新增前流水
 * @property numeric $after_bet 新增後流水
 * @property numeric $before_point 新增前分數
 * @property numeric $after_point 新增後分數
 * @property numeric $before_receive 領取前已領取金額
 * @property numeric $after_receive 領取後已領取金額
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Member|null $member
 *
 * @method static Builder<static>|EventInvitePointLog newModelQuery()
 * @method static Builder<static>|EventInvitePointLog newQuery()
 * @method static Builder<static>|EventInvitePointLog query()
 *
 * @mixin \Eloquent
 */
class EventInvitePointLog extends Model
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
}
