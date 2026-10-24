<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 會員遊戲限制主表 工單4126
 *
 * 與 GameMaintenance（4120 代理層級的遊戲維護）平行但語意不同：
 * 前者是「該款遊戲整條線暫時維護、到點自動恢復」，本表是
 * 「這些會員永久不能玩這些遊戲商／遊戲」，無時間區間、解除即停用。
 *
 * @property int $id 流水號
 * @property int $restrict_type 限制類型 1:遊戲商 2:遊戲
 * @property int $target_scope 對象範圍 1:指定會員 2:會員群組
 * @property int $agent_id 建立此限制的代理ID
 * @property int $status 狀態 0:停用 1:啟用
 * @property string|null $memo 備註
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|MemberGameRestriction newModelQuery()
 * @method static Builder<static>|MemberGameRestriction newQuery()
 * @method static Builder<static>|MemberGameRestriction query()
 *
 * @mixin \Eloquent
 */
class MemberGameRestriction extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /** 本單受限的遊戲商或遊戲（依 restrict_type 解讀 target_id） */
    public function targets(): HasMany
    {
        return $this->hasMany(MemberGameRestrictionTarget::class, 'restriction_id');
    }

    /** 本單受限的會員或會員群組（依 target_scope 擇一欄位） */
    public function members(): HasMany
    {
        return $this->hasMany(MemberGameRestrictionMember::class, 'restriction_id');
    }
}
