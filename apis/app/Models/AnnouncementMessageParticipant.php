<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $announcement_message_id FK對應 announcement_message
 * @property int $type 類型 1: 針對chat_group  2. 針對代理樹狀  3. 針對單一代理  4. 針對特定Member  99. 針對組織
 * @property int|null $chat_group_id 發送給特定的 chat_group
 * @property int|null $hierarchy_agent_id 發送給特定的代理樹狀所有人
 * @property int|null $single_agent_id 發送給特定的代理
 * @property int|null $member_id 發送給特定的會員
 * @property int|null $organization_id 發送給特定的組織
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|AnnouncementMessageParticipant newModelQuery()
 * @method static Builder<static>|AnnouncementMessageParticipant newQuery()
 * @method static Builder<static>|AnnouncementMessageParticipant query()
 *
 * @mixin \Eloquent
 */
class AnnouncementMessageParticipant extends Model
{
    protected $table = 'announcement_messages_participants';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
