<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id 會員系統編號
 * @property int $order_id 儲值訂單編號
 * @property int $level 彩金等級，對應 event_progressive_jackpot_settings.level
 * @property numeric $before_deposit_point 異動前累積儲值金額
 * @property numeric $after_deposit_point 異動後累積儲值金額
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $admin
 * @property-read Member|null $member
 *
 * @method static Builder<static>|EventProgressiveJackpotUserDetailLog newModelQuery()
 * @method static Builder<static>|EventProgressiveJackpotUserDetailLog newQuery()
 * @method static Builder<static>|EventProgressiveJackpotUserDetailLog query()
 *
 * @mixin \Eloquent
 */
class EventProgressiveJackpotUserDetailLog extends Model
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
