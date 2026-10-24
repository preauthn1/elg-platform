<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\EventV2Observer;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name 活動名稱
 * @property string|null $schedule 活動重製時間
 * @property string|null $handler_class 處理該活動邏輯的類別
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|EventV2 newModelQuery()
 * @method static Builder<static>|EventV2 newQuery()
 * @method static Builder<static>|EventV2 query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy([EventV2Observer::class])]
class EventV2 extends Model
{
    protected $table = 'event_v2';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
