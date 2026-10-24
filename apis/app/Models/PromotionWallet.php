<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id events.id
 * @property int $parent agents.id
 * @property int $member_id members.id
 * @property numeric $balance 分數
 * @property int $unlock_type 0:未解綁,1:達成條件,2:未達條件
 * @property int $status 狀態 0:解鎖,1:鎖定
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 操作者
 * @property-read Event|null $event
 * @property-read Collection<int, EventPromotion> $eventPromotions
 * @property-read int|null $event_promotions_count
 * @property-read Member|null $member
 *
 * @method static Builder<static>|PromotionWallet newModelQuery()
 * @method static Builder<static>|PromotionWallet newQuery()
 * @method static Builder<static>|PromotionWallet query()
 *
 * @mixin \Eloquent
 */
class PromotionWallet extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 關聯的 member 帳號
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id')
            ->where('status', 1);
    }

    /**
     * 關聯的活動
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id')
            ->where('status', 1);
    }

    public function eventPromotions(): HasMany
    {
        return $this->hasMany(EventPromotion::class, 'event_id', 'event_id');
    }
}
