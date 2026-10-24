<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id events.id
 * @property int $vendor_id vendors.id
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 操作者
 * @property-read Event|null $event
 * @property-read Vendor|null $vendor
 *
 * @method static Builder<static>|EventPromotion newModelQuery()
 * @method static Builder<static>|EventPromotion newQuery()
 * @method static Builder<static>|EventPromotion query()
 *
 * @mixin \Eloquent
 */
class EventPromotion extends Model
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
        return $this->belongsTo(Event::class, 'event_id');
    }

    /**
     * 關聯的遊戲商
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
