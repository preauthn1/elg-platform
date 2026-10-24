<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 推廣代理 GGR 條件設置主表 工單4124
 *
 * 一筆＝一份「條件設置」，階梯列在 GgrConditionTier。
 * 4121 方案以本表 id 為引用契約：不硬刪、停用靠 status。
 * 僅總代使用，各總代以 general_agent_id 隔離、彼此不互見。
 *
 * @property int $id 流水號(4121引用契約)
 * @property int $general_agent_id 所屬總代agents.id(可見性軸)
 * @property string $name GGR條件標題
 * @property int $logic_type 門檻組合方式 1:或者(OR) 2:和(AND)
 * @property int $active_user_only 僅計算活躍用戶 0:否 1:是
 * @property int $status 狀態 0:停用 1:啟用
 * @property int $created_by 建立人員ID
 * @property int $admin 更新者ID
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|GgrCondition newModelQuery()
 * @method static Builder<static>|GgrCondition newQuery()
 * @method static Builder<static>|GgrCondition query()
 *
 * @mixin \Eloquent
 */
class GgrCondition extends Model
{
    /** logic_type: 四項會員門檻任一達標即合格 */
    public const int LOGIC_TYPE_OR = 1;

    /** logic_type: 四項會員門檻全數達標才合格 */
    public const int LOGIC_TYPE_AND = 2;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /** 階梯列（依 tier 升冪，update 全量替換） */
    public function tiers(): HasMany
    {
        return $this->hasMany(GgrConditionTier::class, 'condition_id');
    }

    /** 建立此條件的代理 */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'created_by');
    }

    /** 最後編輯此條件的代理 */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'admin');
    }
}
