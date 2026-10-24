<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\OrderTypeObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Carbon;

/**
 * @property int $id 系統編號
 * @property int $cash_flow_id 金流商編號
 * @property int $parent 上層支付方式系統編號(order_type)
 * @property string $name 類別名稱
 * @property string $key 支付方式代碼
 * @property int $type 付款方式;1:電子、2:網銀轉帳、3:QR掃碼、4:加密貨幣
 * @property int $currency_id 幣別系統編號(currencies.id)
 * @property string|null $info
 * @property int $choose_bank 是否由我們這邊選擇銀行
 * @property int $need_first_name 是否需要實名
 * @property int $sorts 排序權重
 * @property int $status 狀態 0:停用,1:啟用
 * @property string|null $image_path 圖片路徑
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read AgentCashFlow|null $agentCashFlow
 * @property-read Collection<int, AgentCashFlowOrderType> $agentCashFlowOrderTypes
 * @property-read int|null $agent_cash_flow_order_types_count
 * @property-read CashFlow|null $cashFlow
 * @property-read Currency|null $currency
 * @property-read Collection<int, OrderTypeName> $orderTypeNames
 * @property-read int|null $order_type_names_count
 * @property-read OrderType|null $parentOrderType
 *
 * @method static Builder<static>|OrderType newModelQuery()
 * @method static Builder<static>|OrderType newQuery()
 * @method static Builder<static>|OrderType query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(OrderTypeObserver::class)]
class OrderType extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function orderTypeNames(): HasMany
    {
        return $this->hasMany(OrderTypeName::class, 'type_id', 'id');
    }

    /**
     * 支付方式
     */
    public function cashFlow(): BelongsTo
    {
        return $this->belongsTo(CashFlow::class, 'cash_flow_id', 'id');
    }

    public function agentCashFlowOrderTypes(): HasMany
    {
        return $this->hasMany(AgentCashFlowOrderType::class, 'order_type_id', 'id');
    }

    /**
     * OrderType 透過 AgentCashFlowOrderType 取得 agentCashFlow
     *
     * 🔴 **HasOneThrough 只會取到一筆 ACF**：同一個 order_type 掛多筆 ACF 時無法灰度，
     * 要分流**必須拆 order_type**，而不是在讀取端加挑選邏輯（讀取端挑選只會讓
     * 「查到的」與「實際用到的」不一致）。
     *
     * ⚠️ 這是代收與代付的不對稱點，常被誤以為兩邊行為一致：代付候選是**清單制**故可灰度，
     * 代收經本關聯是**唯一化**故不可灰度。代付那邊的完整條件以
     * {@see \App\Services\WithdrawalPayoutService} 的 fetchPayoutChannels() 為準
     * （同群組同幣別、type=3、status=1，另排除系統自動處理的 cash_flow_id 5 與 6）。
     */
    public function agentCashFlow(): HasOneThrough
    {
        return $this->hasOneThrough(
            AgentCashFlow::class,
            AgentCashFlowOrderType::class,
            'order_type_id', // Foreign key on AgentCashFlowOrderType table
            'id', // Foreign key on AgentCashFlow table
            'id', // Local key on OrderType table
            'agent_cash_flow_id' // Local key on AgentCashFlowOrderType table
        );
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function parentOrderType(): BelongsTo
    {
        return $this->belongsTo(OrderType::class, 'parent');
    }
}
