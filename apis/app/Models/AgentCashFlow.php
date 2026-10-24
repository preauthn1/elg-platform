<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Common;
use App\Observers\AgentCashFlowObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @knowledge rules/deposit-order-fees
 * @knowledge rules/payment-channel-management
 * @knowledge rules/payment-cost-summary
 *
 * @property int $id 流水號
 * @property int $cash_flow_info_id
 * @property int $cash_flow_id 金流商系統編號
 * @property int|null $cash_flow_type 支付類別（繼承 cash_flows.type）；便利欄，權威=cash_flows.type，NULL=尚未同步
 * @property int $agent_group_id 支付組別編號（對應 agents.cash_flow_group_id，見 Agent::agentCashFlows()）
 * @property int|null $currency_id
 * @property numeric $rate 轉換比例(2025/05/05提升至9位)
 * @property numeric $tax 手續費
 * @property numeric $tax_percentage 手續費(百分比)
 * @property string $name 名稱
 * @property array<array-key, mixed> $info API使用資訊
 * @property int $sorts 排序權重
 * @property numeric $cost
 * @property int $status 設置啟用狀態 0:停用, 1:啟用
 * @property int $backend_display 後台顯示 0:隱藏, 1:顯示
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property Carbon|null $deleted_at 軟刪除時間；NULL=有效
 * @property int $admin 更新者ID
 * @property-read Collection<int, AgentCashFlowOrderType> $agentCashFlowOrderTypes
 * @property-read int|null $agent_cash_flow_order_types_count
 * @property-read CashFlow|null $cashFlow
 * @property-read Currency|null $currency
 * @property-read string|null $third_party_code
 * @property-read Collection<int, OrderType> $orderTypes
 * @property-read int|null $order_types_count
 *
 * @method static Builder<static>|AgentCashFlow newModelQuery()
 * @method static Builder<static>|AgentCashFlow newQuery()
 * @method static Builder<static>|AgentCashFlow query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(AgentCashFlowObserver::class)]
class AgentCashFlow extends Model
{
    use SoftDeletes;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'info' => 'array',
    ];

    public function agentCashFlowOrderTypes(): HasMany
    {
        return $this->hasMany(AgentCashFlowOrderType::class, 'agent_cash_flow_id', 'id');
    }

    public function cashFlow(): BelongsTo
    {
        return $this->belongsTo(CashFlow::class, 'cash_flow_id', 'id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function orderTypes(): BelongsToMany
    {
        return $this->belongsToMany(OrderType::class, 'agent_cash_flow_order_types', 'agent_cash_flow_id', 'order_type_id');
    }

    /**
     * 修改器：取得關聯 cashFlow 的 third_party_code
     */
    public function getThirdPartyCodeAttribute(): ?string
    {
        return $this->cashFlow ? $this->cashFlow->third_party_code : null;
    }

    /**
     * 盤點本列在五張業務事實表的引用數（只回傳有引用的表）
     *
     * 有任何業務足跡＝真配置，禁止刪除（歷史引用會斷裂且對帳／客服查單受害）；
     * 供 {@see AgentCashFlowObserver::deleting} 擋刪與 fix:acf-delete 預檢共用。
     * order_histories / ota_orders / summary_profit_share_payment_costs 無對應 Model，
     * 以 DB::table 聚合查詢（僅計數，紅線允許的例外）。
     *
     * @return array<string, int> 表名 => 引用數（皆 >0）
     */
    public function businessReferenceCounts(): array
    {
        return self::businessReferenceCountsFor([$this->id])[$this->id] ?? [];
    }

    /**
     * 盤點多筆 ACF 在五張業務事實表的引用數（批次版，fix:acf-delete --orphans 掃描用，免逐筆 N+1）
     *
     * 判準與單筆版同源：本方法是唯一實作，單筆 {@see businessReferenceCounts} 委派至此。
     * selectRaw 為零外部輸入的常數聚合，whereIn 綁定參數。
     *
     * @param  array<int, int>  $acfIds
     * @return array<int, array<string, int>> acf_id => [表名 => 引用數（皆 >0）]；零足跡者為空陣列
     */
    public static function businessReferenceCountsFor(array $acfIds): array
    {
        if ($acfIds === []) {
            return [];
        }

        $sources = [
            'orders' => Order::query(),
            'order_histories' => DB::table('order_histories'),
            'withdrawal_payout_records' => WithdrawalPayoutRecord::query(),
            'ota_orders' => DB::table('ota_orders'),
            'summary_profit_share_payment_costs' => DB::table('summary_profit_share_payment_costs'),
        ];

        $result = array_fill_keys(array_map(intval(...), $acfIds), []);

        foreach ($sources as $table => $query) {
            $rows = $query
                ->whereIn('agent_cash_flow_id', $acfIds)
                ->groupBy('agent_cash_flow_id')
                ->selectRaw('agent_cash_flow_id, COUNT(*) AS reference_count')
                ->get();

            foreach ($rows as $row) {
                $result[(int) $row->agent_cash_flow_id][$table] = (int) $row->reference_count;
            }
        }

        return $result;
    }

    /**
     * 支付商手續費（成本）：付給金流商的錢，不是向會員收的 tax。
     *
     * 規則（target/tickets#4361，與 PaymentCostSummaryService 同口徑）：
     * - 代收：三方回調在 PaymentService::updateMemberBalance 寫 status 3 時計算；自助上分審核在 API_Agent 不經 APIs，於 AutoDepositController 建單時計算（彙總只計 status=3，待審核單不入成本）。
     * - 必須以「站點主幣別金額」× cost 計算：代收傳 orders.point，代付傳 amount × rate；禁止傳會員下單幣別的 amount。
     * - 一律截到小數第 4 位（不四捨五入），寫入 orders.payment_cost／withdrawal_orders.payment_cost。
     * - cost 為費率（0.02 = 2%），沿用 agent_cash_flows.cost；agent_cash_flow_order_types.cost 尚無任何流程使用，禁止混用。
     */
    public function paymentCostFor(string $point): string
    {
        $cost = Common::numAdd('0', (string) ($this->cost ?? 0), 4);

        return Common::numFloor(Common::numMul($point, $cost, 8), 4);
    }
}
