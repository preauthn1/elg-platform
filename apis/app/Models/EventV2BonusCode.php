<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $event_v2_user_join_id event_v2_user_join.id
 * @property int $vendor_id vendors.id
 * @property string $bonus_code 活動優惠代碼
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read EventV2UserJoin|null $eventV2UserJoin
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2BonusCode newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2BonusCode newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2BonusCode query()
 *
 * @mixin \Eloquent
 */
class EventV2BonusCode extends Model
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
