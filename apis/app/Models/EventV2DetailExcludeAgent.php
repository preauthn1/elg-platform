<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $event_v2_detail_id
 * @property int $agent_id
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2DetailExcludeAgent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2DetailExcludeAgent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2DetailExcludeAgent query()
 *
 * @mixin \Eloquent
 */
class EventV2DetailExcludeAgent extends Model
{
    protected $fillable = [
        'event_v2_detail_id',
        'agent_id',
    ];
}
