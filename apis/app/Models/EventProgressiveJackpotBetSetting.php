<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $game_type_id game_types.id
 * @property numeric $rate 解鎖比例
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $admin
 *
 * @method static Builder<static>|EventProgressiveJackpotBetSetting newModelQuery()
 * @method static Builder<static>|EventProgressiveJackpotBetSetting newQuery()
 * @method static Builder<static>|EventProgressiveJackpotBetSetting query()
 *
 * @mixin \Eloquent
 */
class EventProgressiveJackpotBetSetting extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
