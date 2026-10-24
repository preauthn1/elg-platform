<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $schedule_name
 * @property string $input
 * @property int $exit_code
 * @property string $start_time
 * @property string $end_time
 * @property numeric $usages
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduleLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduleLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduleLog query()
 *
 * @mixin \Eloquent
 */
class ScheduleLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
    ];
}
