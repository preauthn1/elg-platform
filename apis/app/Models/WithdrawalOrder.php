<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Common;
use App\Models\Traits\HasMember;
use App\Observers\WithdrawalOrderObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @knowledge rules/payment-channel-management
 * @knowledge rules/payment-cost-summary
 *
 * @property int $id
 * @property int $bank_id banks.id
 * @property string $bank_name 銀行名稱
 * @property string $bank_code 銀行代碼
 * @property string $account_name 帳戶名稱
 * @property string $account_no 帳戶帳號
 * @property int $agent_group_id 支付組別編號（存的是 agents.cash_flow_group_id，不是金流商戶 ID）
 * @property int $agent_id withdrawal_order_agents.id
 * @property int $member_id 帳號系統編號(Members)
 * @property numeric $amount 金額
 * @property numeric $rate 匯率(2025/05/05提升至9位)
 * @property numeric $point 平台點數
 * @property numeric $tax 手續費
 * @property numeric $payment_cost 支付商手續費（成本）：AgentCashFlow::paymentCostFor()，於派款成功時寫入（算法見 paymentCostVia()）；CCPayment 例外，寫回查的實際網路費（見其 WithdrawalCallback）
 * @property int $withdraw_seq 當日第幾筆提款（GMT+8，從 1 起算）；0=非會員前台建單
 * @property int $tax_source 手續費來源 WithdrawalTaxSourceEnum：0=銀行、1=會員群組階梯
 * @property string $third_party_order_no 三方金流訂單編號
 * @property string|null $third_party_response 三方API的response
 * @property int $status 狀態 0:訂單建立,1:待審核,2:拒絕,3:等待付款,4:已付款,9:退回,21:代付請求中,22:代付處理中,24:已付款(代付),29:代付回應失敗
 * @property string|null $check_time 審核時間
 * @property string|null $audit_time
 * @property int|null $audit_operator
 * @property string|null $payment_time 付款時間
 * @property int|null $payment_operator
 * @property string|null $memo 備註
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property int $operator 審核人
 * @property string $change_logs 修改歷程
 * @property-read Agent|null $agent
 * @property-read AgentCashFlow|null $agentCashFlow
 * @property-read Bank|null $bank
 * @property-read Member|null $member
 * @property-read Agent|null $operatorAgent
 * @property-read OrderType|null $orderType
 * @property-read mixed $owner
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WithdrawalPayoutRecord> $payoutRecords
 * @property-read int|null $payout_records_count
 * @property-read TransactionLog|null $transactionLog
 *
 * @method static \Database\Factories\WithdrawalOrderFactory factory($count = null, $state = [])
 * @method static Builder<static>|WithdrawalOrder newModelQuery()
 * @method static Builder<static>|WithdrawalOrder newQuery()
 * @method static Builder<static>|WithdrawalOrder query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(WithdrawalOrderObserver::class)]
class WithdrawalOrder extends Model
{
    use HasFactory;
    use HasMember;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id', 'id');
    }

    public function transactionLog(): HasOne
    {
        return $this->hasOne(TransactionLog::class, 'order_id', 'id');
    }

    public function operatorAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'operator', 'id');
    }

    public function member(): BelongsTo
    {
        // if ($this->agent_id > 0) {
        //     return $this->agent();
        // }
        return $this->belongsTo(Member::class, 'member_id', 'id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }

    /**
     * 取得訂單下單者，需搭配 with(['agent', 'member']) 預載：
     * - member_id > 0：一般會員提款，下單者為 Member
     * - member_id = 0：代理提款訂單，下單者為 Agent
     */
    protected function owner(): Attribute
    {
        return Attribute::get(
            fn () => $this->member_id > 0 ? $this->member : $this->agent
        );
    }

    public function payoutRecords(): HasMany
    {
        return $this->hasMany(WithdrawalPayoutRecord::class, 'withdrawal_order_id', 'id');
    }

    public function agentCashFlow(): BelongsTo
    {
        return $this->belongsTo(AgentCashFlow::class);
    }

    public function orderType(): BelongsTo
    {
        return $this->belongsTo(OrderType::class, 'order_type_id');
    }

    /**
     * 派款成功時的支付商手續費（成本）：以實際出款渠道的 cost × (amount × rate) 計算。
     *
     * 渠道取值順序：指定的 payout record → 本單最新一筆 payout record；
     * 都沒有（純人工出款）回 '0'，人工出款的成本由 API_Agent 後台以手動渠道（cash_flow_id=6）寫入。
     */
    public function paymentCostVia(?WithdrawalPayoutRecord $record = null): string
    {
        $agentCashFlowId = (int) ($record?->agent_cash_flow_id
            ?? $this->payoutRecords()->latest('id')->value('agent_cash_flow_id')
            ?? 0);

        $agentCashFlow = $agentCashFlowId > 0 ? AgentCashFlow::query()->find($agentCashFlowId) : null;

        if ($agentCashFlow === null) {
            return '0';
        }

        $point = Common::numMul((string) $this->amount, (string) ($this->rate ?? 1), 4);

        return $agentCashFlow->paymentCostFor($point);
    }
}
