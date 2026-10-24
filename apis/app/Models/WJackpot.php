<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * W-Jackpot 活動明細
 *
 * @property int $id
 * @property int $event_id 活動ID
 * @property int $event_detail_id event_details.id
 * @property int $no 期數
 * @property string|null $draw_time 開獎時間
 * @property string|null $draw_number 開獎號碼
 * @property string|null $draw_number_2 開獎號碼2
 * @property string|null $draw_number_3 開獎號碼3
 * @property string|null $draw_number_4 開獎號碼4（逗號分隔多筆）
 * @property string|null $draw_number_5 開獎號碼5（逗號分隔多筆）
 * @property string|null $remark 備註
 * @property int $status 狀態; 0:未開獎, 1:已開奬
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read Event $event
 * @property-read EventDetail $eventDetail
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WJackpotTicket> $tickets
 *
 * @method static Builder<static>|WJackpot newModelQuery()
 * @method static Builder<static>|WJackpot newQuery()
 * @method static Builder<static>|WJackpot query()
 *
 * @mixin \Eloquent
 */
class WJackpot extends Model
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

    public function tickets(): HasMany
    {
        return $this->hasMany(WJackpotTicket::class, 'w_jackpot_id');
    }
}
