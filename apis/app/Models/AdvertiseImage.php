<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $image_url 圖片路徑
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 操作人員
 *
 * @method static Builder<static>|AdvertiseImage newModelQuery()
 * @method static Builder<static>|AdvertiseImage newQuery()
 * @method static Builder<static>|AdvertiseImage query()
 *
 * @mixin \Eloquent
 */
class AdvertiseImage extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
