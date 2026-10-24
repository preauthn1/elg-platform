<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id 流水號
 * @property int $cash_flow_id cash_flow_id_支付廠商ID(每家會有固定編號)
 * @property int $agent_cash_flow_id agent_cash_flows.id
 * @property int $withdrawal_order_id 提款單ID
 * @property string $transaction_no 我方系統單號
 * @property string $third_party_transaction_no 三方系統單號
 * @property numeric $result_amount 三方回應的金額
 * @property string $result_code 三方回應的交易狀態
 * @property \App\Enums\WithdrawalPayoutStatusEnum $is_success_payout 是否定義為成功單
 * @property string $transaction_time 三方回應的交易時間（原始字串，時區依代付商而異；建單時為空，只由回調寫入）
 * @property string $sign 加密簽名
 * @property string $payout_order_request_body 代付請求_request資訊
 * @property string $payout_order_response 代付請求_response資訊
 * @property string $callback_request_body 原始request
 * @property string $info_1 額外資訊1
 * @property string $info_2 額外資訊2
 * @property string $info_3 額外資訊3
 * @property string|null $callback_time 回調時間
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read AgentCashFlow|null $agentCashFlow
 * @property-read CashFlow|null $cashFlow
 * @property-read WithdrawalOrder|null $withdrawalOrder
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WithdrawalPayoutRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WithdrawalPayoutRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WithdrawalPayoutRecord query()
 *
 * @mixin \Eloquent
 */
class WithdrawalPayoutRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_success_payout' => \App\Enums\WithdrawalPayoutStatusEnum::class,
        ];
    }

    public function cashFlow(): BelongsTo
    {
        return $this->belongsTo(CashFlow::class, 'cash_flow_id', 'id');
    }

    public function agentCashFlow(): BelongsTo
    {
        return $this->belongsTo(AgentCashFlow::class, 'agent_cash_flow_id', 'id');
    }

    public function withdrawalOrder(): BelongsTo
    {
        return $this->belongsTo(WithdrawalOrder::class, 'withdrawal_order_id', 'id');
    }
}
