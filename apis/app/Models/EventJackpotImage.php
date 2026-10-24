<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 積寶活動多語系圖片
 *
 * @property int $id
 * @property int $language_id languages.id
 * @property int $event_jackpot_id event_jackpots.id
 * @property string $image 圖片
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read EventJackpot $jackpot
 *
 * @method static Builder<static>|EventJackpotImage newModelQuery()
 * @method static Builder<static>|EventJackpotImage newQuery()
 * @method static Builder<static>|EventJackpotImage query()
 *
 * @mixin \Eloquent
 */
class EventJackpotImage extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function jackpot(): BelongsTo
    {
        return $this->belongsTo(EventJackpot::class, 'event_jackpot_id');
    }
}
