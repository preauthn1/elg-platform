<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $language_id 語系系統編號
 * @property int $event_id 活動編號
 * @property string $image 圖片
 * @property string $image_pc 電腦版圖片
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 *
 * @method static Builder<static>|EventImage newModelQuery()
 * @method static Builder<static>|EventImage newQuery()
 * @method static Builder<static>|EventImage query()
 *
 * @mixin \Eloquent
 */
class EventImage extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
