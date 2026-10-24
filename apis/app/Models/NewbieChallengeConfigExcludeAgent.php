<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $config_id
 * @property int $agent_id
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NewbieChallengeConfigExcludeAgent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NewbieChallengeConfigExcludeAgent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NewbieChallengeConfigExcludeAgent query()
 *
 * @mixin \Eloquent
 */
class NewbieChallengeConfigExcludeAgent extends Model
{
    protected $fillable = [
        'config_id',
        'agent_id',
    ];
}
