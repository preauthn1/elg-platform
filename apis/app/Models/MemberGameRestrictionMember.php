<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 會員遊戲限制－受限對象 工單4126
 *
 * 依主表 target_scope 擇一使用 member_id 或 member_group_id，另一欄為 0。
 *
 * @property int $id 流水號
 * @property int $restriction_id member_game_restrictions.id
 * @property int $member_id members.id（target_scope=1 時使用）
 * @property int $member_group_id member_groups.id（target_scope=2 時使用）
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|MemberGameRestrictionMember newModelQuery()
 * @method static Builder<static>|MemberGameRestrictionMember newQuery()
 * @method static Builder<static>|MemberGameRestrictionMember query()
 *
 * @mixin \Eloquent
 */
class MemberGameRestrictionMember extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function restriction(): BelongsTo
    {
        return $this->belongsTo(MemberGameRestriction::class, 'restriction_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
