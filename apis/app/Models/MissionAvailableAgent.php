<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $mission_id
 * @property int $agent_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<static>|MissionAvailableAgent newModelQuery()
 * @method static Builder<static>|MissionAvailableAgent newQuery()
 * @method static Builder<static>|MissionAvailableAgent query()
 *
 * @mixin \Eloquent
 */
class MissionAvailableAgent extends Model
{
    protected $guarded = [
        'created_at',
        'updated_at',
    ];
}
