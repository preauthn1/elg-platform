<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 推廣代理 GGR 結算結果 工單4125
 *
 * 一列＝一個方案×代理×結算週期的分潤結果（4125 派發紀錄頁資料源）。
 * 百分比與費用是結算當下的快照，方案改設定不回溯。寫入方是後續批次的
 * 結算引擎；本批只有查詢。unique(plan_id, agent_id, period_start)
 * 防引擎重跑寫重複。僅總代使用，general_agent_id 隔離。
 *
 * @property int $id 流水號
 * @property int $general_agent_id 所屬總代agents.id(可見性軸)
 * @property int $plan_id 來源方案ggr_plans.id
 * @property int $agent_id 被結算的代理agents.id
 * @property int $settlement_cycle 結算週期快照 1:每日 2:每週 3:每月
 * @property string $period_start 結算區間起(Y-m-d)
 * @property string $period_end 結算區間迄(Y-m-d)
 * @property string $ggr_amount 原始總GGR(經4123門檻/上限/加權後)
 * @property string $bonus_rate 獎金百分比%快照
 * @property string $ggr_rate GGR百分比%快照
 * @property string $condition_rate 條件百分比%快照(4124命中階應得%)
 * @property string $admin_fee_rate 管理費%快照
 * @property string $handling_fee 手續費快照
 * @property string $bonus_amount 紅利金額設定(預留)
 * @property string $final_amount 最終金額(本期公式實算值,可為負)
 * @property string $payable_amount 发扬沖抵後實發金額(>=0,批次七發放依據)
 * @property string $carry_in_amount 发扬:上期結轉帶入(遞延餘額,<=0)
 * @property string $carry_out_amount 发扬:本期結轉帶出(!=0即有結轉;最新一筆即當前遞延餘額)
 * @property array|null $calc_snapshot 計算明細快照
 * @property int $payout_status 發放狀態(GgrPayoutStatusEnum)
 * @property string|null $paid_at 發放時間
 * @property string|null $remark 備註
 * @property int|null $admin 管理者ID
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|GgrSettlement newModelQuery()
 * @method static Builder<static>|GgrSettlement newQuery()
 * @method static Builder<static>|GgrSettlement query()
 *
 * @mixin \Eloquent
 */
class GgrSettlement extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'calc_snapshot' => 'array',
        // 以 UTC 落庫，對外（4125 Resource）一律經 toBackendTime() 轉業務時區
        'paid_at' => 'datetime',
    ];

    /** 來源方案 */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(GgrPlan::class, 'plan_id');
    }

    /** 被結算的代理 */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /** 管理者（發放/備註操作者） */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'admin');
    }
}
