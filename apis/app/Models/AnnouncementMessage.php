<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title 標題
 * @property string $message 訊息內容
 * @property string $memo 備註
 * @property int $status 狀態，1:開啟  0:刪除關閉
 * @property int $organization_id 所屬組織
 * @property string|null $start_time 顯示起始時間
 * @property string|null $end_time 顯示結束時間
 * @property Carbon $created_at 建立日期
 * @property int $created_by 建立人
 * @property Carbon $updated_at 更新日期
 * @property int $updated_by 修改人
 * @property-read Collection<int, AnnouncementMessageParticipant> $participants
 * @property-read int|null $participants_count
 * @property-read Collection<int, AnnouncementMessageRead> $reads
 * @property-read int|null $reads_count
 *
 * @method static Builder<static>|AnnouncementMessage newModelQuery()
 * @method static Builder<static>|AnnouncementMessage newQuery()
 * @method static Builder<static>|AnnouncementMessage query()
 *
 * @mixin \Eloquent
 */
class AnnouncementMessage extends Model
{
    protected $table = 'announcement_messages';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 群發公告參與者
     */
    public function participants(): HasMany
    {
        return $this->hasMany(AnnouncementMessageParticipant::class);
    }

    /**
     * 群發公告閱讀者
     */
    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementMessageRead::class);
    }
}
