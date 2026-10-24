<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $announcement_message_id FK對應 announcement_message
 * @property int $member_id 會員id
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|AnnouncementMessageRead newModelQuery()
 * @method static Builder<static>|AnnouncementMessageRead newQuery()
 * @method static Builder<static>|AnnouncementMessageRead query()
 *
 * @mixin \Eloquent
 */
class AnnouncementMessageRead extends Model
{
    protected $table = 'announcement_messages_reads';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
