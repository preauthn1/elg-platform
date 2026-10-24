<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static Builder<static>|AgentSetting newModelQuery()
 * @method static Builder<static>|AgentSetting newQuery()
 * @method static Builder<static>|AgentSetting query()
 *
 * @mixin \Eloquent
 */
class AgentSetting extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
