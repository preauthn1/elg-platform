<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $event_v2_detail_id
 * @property int $vendor_id
 * @property-read EventV2Detail $eventV2Detail
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2DetailVendor newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2DetailVendor newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2DetailVendor query()
 *
 * @mixin \Eloquent
 */
class EventV2DetailVendor extends Model
{
    protected $fillable = [
        'event_v2_detail_id',
        'vendor_id',
    ];

    public function eventV2Detail(): BelongsTo
    {
        return $this->belongsTo(EventV2Detail::class);
    }
}
