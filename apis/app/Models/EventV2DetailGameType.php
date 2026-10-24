<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $event_v2_detail_id
 * @property int $game_type_id
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2DetailGameType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2DetailGameType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2DetailGameType query()
 *
 * @mixin \Eloquent
 */
class EventV2DetailGameType extends Model
{
    protected $fillable = [
        'event_v2_detail_id',
        'game_type_id',
    ];
}
