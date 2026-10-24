<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $member_id
 * @property \Illuminate\Support\Carbon $last_read_time 會員最後已讀時間
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemNotificationRead newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemNotificationRead newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemNotificationRead query()
 *
 * @mixin \Eloquent
 */
class SystemNotificationRead extends Model
{
    protected $table = 'system_notification_reads';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'last_read_time' => 'datetime',
    ];
}
