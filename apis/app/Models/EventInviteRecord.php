<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $event_id events.id
 * @property int $member_id members.id(被邀請人)
 * @property int $invite_member_id members.id(邀請人)
 * @property int $ip_duplicate 註冊IP是否重複;1:是、0:否
 * @property int $phone_duplicate 手機號碼是否重複;1:是、0:否
 * @property int $realname_duplicate 真實姓名是否重複;1:是、0:否
 * @property int $telegram_id_duplicate Telegram ID是否重複;1:是、0:否
 * @property string|null $block_member_id members.id(阻擋人)
 * @property int|null $prize_member_id 領獎後綁定prize_members.id
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property-read Member|null $member
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventInviteRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventInviteRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventInviteRecord query()
 *
 * @mixin \Eloquent
 */
class EventInviteRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 被邀請人（members.id = event_invite_records.member_id）。
     *
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
