<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $image_url 圖片路徑
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 操作人員
 *
 * @method static \Database\Factories\PlatformAnnouncementImageFactory factory($count = null, $state = [])
 * @method static Builder<static>|PlatformAnnouncementImage newModelQuery()
 * @method static Builder<static>|PlatformAnnouncementImage newQuery()
 * @method static Builder<static>|PlatformAnnouncementImage query()
 *
 * @mixin \Eloquent
 */
class PlatformAnnouncementImage extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
