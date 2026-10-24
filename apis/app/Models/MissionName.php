<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $language_id
 * @property int $mission_id
 * @property string|null $name
 * @property string|null $description
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MissionName newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MissionName newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MissionName query()
 *
 * @mixin \Eloquent
 */
class MissionName extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
