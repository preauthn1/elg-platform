<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id events.id
 * @property int $agent_id agents.id
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|EventExcludeAgent newModelQuery()
 * @method static Builder<static>|EventExcludeAgent newQuery()
 * @method static Builder<static>|EventExcludeAgent query()
 *
 * @mixin \Eloquent
 */
class EventExcludeAgent extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
