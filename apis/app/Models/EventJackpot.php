<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 積寶活動期數設定
 *
 * @property int $id 流水號
 * @property int $event_id 活動編號
 * @property int $type 1:流水,2:輸分金額
 * @property string $start_time 開始日期
 * @property string $end_time 結束日期
 * @property string $expire_date 獎勵遊戲次數到期日
 * @property int $expire_hour 獎勵領取期限(小時)
 * @property numeric $achieve_point 達成分數
 * @property int $member_number 最低符合資格玩家數
 * @property int $agent_number 最低符合資格代理數
 * @property int $pay_number 派發獎勵人數
 * @property int $number_of_member_in_agent 每個代理可中獎人數
 * @property numeric $point 獎勵金額
 * @property numeric $flow_limit_multiple 稽核流水倍數
 * @property string|null $image 圖片
 * @property int $sort 排序
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read Event $event
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EventJackpotImage> $images
 *
 * @method static Builder<static>|EventJackpot newModelQuery()
 * @method static Builder<static>|EventJackpot newQuery()
 * @method static Builder<static>|EventJackpot query()
 *
 * @mixin \Eloquent
 */
class EventJackpot extends Model
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

    public function images(): HasMany
    {
        return $this->hasMany(EventJackpotImage::class, 'event_jackpot_id');
    }
}
