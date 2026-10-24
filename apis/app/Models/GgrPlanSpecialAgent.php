<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 推廣代理 GGR 方案的特殊用戶 override 工單4121
 *
 * 一列＝一個被 override 的代理：結算時整組改吃本列綁定的
 * （遊戲商設定＋條件設定），優先於方案主表的成員下線設置。
 *
 * @property int $id 流水號(不對外引用,update全量替換)
 * @property int $plan_id ggr_plans.id
 * @property int $agent_id 被override的代理agents.id
 * @property int $vendor_setting_id override的ggr_vendor_settings.id
 * @property int $condition_id override的ggr_conditions.id
 * @property int $admin 更新者ID
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|GgrPlanSpecialAgent newModelQuery()
 * @method static Builder<static>|GgrPlanSpecialAgent newQuery()
 * @method static Builder<static>|GgrPlanSpecialAgent query()
 *
 * @mixin \Eloquent
 */
class GgrPlanSpecialAgent extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /** 所屬方案 */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(GgrPlan::class, 'plan_id');
    }

    /** 被 override 的代理 */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /** override 綁定的遊戲商設定 */
    public function vendorSetting(): BelongsTo
    {
        return $this->belongsTo(GgrVendorSetting::class, 'vendor_setting_id');
    }

    /** override 綁定的條件設定 */
    public function condition(): BelongsTo
    {
        return $this->belongsTo(GgrCondition::class, 'condition_id');
    }
}
