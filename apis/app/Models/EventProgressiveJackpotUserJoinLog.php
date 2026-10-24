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
 * @property Carbon|null $summary_start_time 統計時間，type=1(解鎖彩金) SP 寫入時用來避免同會員同遊戲類型同區間重複累加；type=2/3 由 PHP 端寫入，固定為 NULL
 * @property int $level 彩金等級，對應 event_progressive_jackpot_settings.level
 * @property int $type 類型 1:解鎖彩金, 2:領取彩金, 3:修改狀態
 * @property int $game_type 遊戲類型
 * @property numeric $rate 投注轉換比例
 * @property numeric $bet 投注金額
 * @property numeric $before_point 異動前已解鎖金額
 * @property numeric $after_point 異動後已解鎖金額
 * @property numeric $before_receive 異動前已領取金額
 * @property numeric $after_receive 異動後已領取金額
 * @property int $before_status 異動前狀態
 * @property int $after_status 異動後狀態
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $admin
 * @property-read Member|null $member
 *
 * @method static Builder<static>|EventProgressiveJackpotUserJoinLog newModelQuery()
 * @method static Builder<static>|EventProgressiveJackpotUserJoinLog newQuery()
 * @method static Builder<static>|EventProgressiveJackpotUserJoinLog query()
 *
 * @mixin \Eloquent
 */
class EventProgressiveJackpotUserJoinLog extends Model
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
