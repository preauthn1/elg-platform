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
 * @property int $level 等級
 * @property int|null $register_ip 當下的IP
 * @property string|null $mobile 當下的手機號
 * @property string|null $realname 當下的真實姓名
 * @property Carbon|null $expire_time 過期時間
 * @property numeric $reward 彩金金額
 * @property numeric $last_point 上次已解鎖金額(前台通知用)
 * @property numeric $point 已解鎖金額
 * @property numeric $receive 已領取金額
 * @property int $status 狀態 0:進行中, 1:等待解鎖, 2:已完成, 3:已過期, 4:已鎖定(IP、手機號、真實姓名重複), 5:已關閉(已完成且領取完畢，不再變動)
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $admin
 * @property-read Member|null $member
 *
 * @method static Builder<static>|EventProgressiveJackpotUserJoin newModelQuery()
 * @method static Builder<static>|EventProgressiveJackpotUserJoin newQuery()
 * @method static Builder<static>|EventProgressiveJackpotUserJoin query()
 *
 * @mixin \Eloquent
 */
class EventProgressiveJackpotUserJoin extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        // EventProgressiveJackpotLevelCardResource/EventProgressiveJackpotSummaryResource 會呼叫
        // ->toFrontendTime()，沒有這個 cast 的話 expire_time 只是原始字串，非 NULL 時直接報錯
        'expire_time' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
