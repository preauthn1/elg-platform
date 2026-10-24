<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 推廣代理 GGR 方案主表 工單4121
 *
 * 一筆＝一個 GGR 分潤方案。成員下線設置直接綁在主表兩顆 FK
 * （downline_vendor_setting_id / downline_condition_id），特殊用戶
 * override 在 GgrPlanSpecialAgent。批次三結算/4125 以本表 id 為
 * 引用契約：不硬刪、停用靠 status。僅總代使用，general_agent_id 隔離。
 *
 * @property int $id 流水號(批次三引用契約)
 * @property int $general_agent_id 所屬總代agents.id(可見性軸)
 * @property string $name GGR標題
 * @property int $settlement_cycle 結算週期(GgrSettlementCycleEnum) 1:每日 2:每週 3:每月
 * @property int|null $pending_settlement_cycle 待生效結算週期(引擎推進到pending_cycle_from時promote)
 * @property string|null $pending_cycle_from 新週期生效日(切換當下當期的下一期起點,GMT+8業務日)
 * @property int $agent_group_id 代理群組agent_groups.id(type=1)
 * @property string $start_date 開始日期(Y-m-d)
 * @property int $carry_negative 发扬 0:否 1:負數GGR用原始數據結轉下期
 * @property string $admin_fee_rate 管理費%
 * @property string $handling_fee 手續費(固定金額)
 * @property string $ggr_rate GGR百分比%
 * @property string $bonus_rate 獎金百分比%
 * @property int $kyc_unique_bank_account 銀行帳號唯一限制
 * @property int $kyc_unique_contact_no 手機號碼唯一限制
 * @property int $kyc_unique_email 信箱唯一限制
 * @property int $kyc_unique_ip IP唯一限制
 * @property int $kyc_unique_name 姓名唯一限制
 * @property int $kyc_violate_count 違規次數
 * @property int $downline_vendor_setting_id 成員下線:ggr_vendor_settings.id
 * @property int $downline_condition_id 成員下線:ggr_conditions.id
 * @property int $status 狀態 0:停用 1:啟用
 * @property int $created_by 建立人員ID
 * @property int $admin 更新者ID
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|GgrPlan newModelQuery()
 * @method static Builder<static>|GgrPlan newQuery()
 * @method static Builder<static>|GgrPlan query()
 *
 * @mixin \Eloquent
 */
class GgrPlan extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /** 特殊用戶 override 列（update 全量替換） */
    public function specialAgents(): HasMany
    {
        return $this->hasMany(GgrPlanSpecialAgent::class, 'plan_id');
    }

    /** 成員下線設置綁定的遊戲商設定 */
    public function downlineVendorSetting(): BelongsTo
    {
        return $this->belongsTo(GgrVendorSetting::class, 'downline_vendor_setting_id');
    }

    /** 成員下線設置綁定的條件設定 */
    public function downlineCondition(): BelongsTo
    {
        return $this->belongsTo(GgrCondition::class, 'downline_condition_id');
    }

    /** 建立此方案的代理 */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'created_by');
    }

    /** 最後編輯此方案的代理 */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'admin');
    }
}
