<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $advertise_id advertises.id
 * @property int $agent_id agents.id
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdvertiseExcludeAgent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdvertiseExcludeAgent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdvertiseExcludeAgent query()
 *
 * @mixin \Eloquent
 */
class AdvertiseExcludeAgent extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
