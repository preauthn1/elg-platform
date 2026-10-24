<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id
 * @property string $last_read_time 會員最後已讀時間
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static \Database\Factories\PlatformAnnouncementReadFactory factory($count = null, $state = [])
 * @method static Builder<static>|PlatformAnnouncementRead newModelQuery()
 * @method static Builder<static>|PlatformAnnouncementRead newQuery()
 * @method static Builder<static>|PlatformAnnouncementRead query()
 *
 * @mixin \Eloquent
 */
class PlatformAnnouncementRead extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
