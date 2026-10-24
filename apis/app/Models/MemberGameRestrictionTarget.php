<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 會員遊戲限制－受限標的 工單4126
 *
 * target_id 依主表 restrict_type 解讀：1 為 vendors.id、2 為 games.id。
 * 遊戲商類型存 vendor_id 而不展開成遊戲，該商日後新增的遊戲自動納入限制。
 *
 * @property int $id 流水號
 * @property int $restriction_id member_game_restrictions.id
 * @property int $target_id 受限標的 ID（vendors.id 或 games.id）
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|MemberGameRestrictionTarget newModelQuery()
 * @method static Builder<static>|MemberGameRestrictionTarget newQuery()
 * @method static Builder<static>|MemberGameRestrictionTarget query()
 *
 * @mixin \Eloquent
 */
class MemberGameRestrictionTarget extends Model
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
}
