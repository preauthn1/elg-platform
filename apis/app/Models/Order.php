<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\HasMember;
use App\Observers\OrderObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id 系統編號
 * @property string $order_no 訂單編號
 * @property string $third_party_order_no 三方金流訂單編號
 * @property int $agent_cash_flow_id 金流商戶資料編號
 * @property int $cash_flow_info_id cash_flow_infos.id
 * @property int|null $cash_flow_id 支付商ID; cash_flows.id
 * @property string $third_party_id 支付商訂單號
 * @property int $order_type_id 訂單類別(FK_OrderType)
 * @property int $currency_id 貨幣類別(FK_Currency)
 * @property int $agent_id withdrawal_order_agents.id
 * @property int $member_id 帳號系統編號(User)
 * @property numeric $amount 金額
 * @property numeric $rate 當下匯率轉換(2025/05/05提升至9位)
 * @property numeric $tax 手續費
 * @property numeric $payment_cost 支付商手續費（成本）：AgentCashFlow::paymentCostFor()，三方代收於回調成功（status 寫成 3）時寫入，自助上分於建單時寫入；CCPayment 例外，寫回查的實際服務費（見其 DepositCallback）
 * @property numeric $point 點數
 * @property numeric $balance 儲值後餘額
 * @property string $ip ip address
 * @property string|null $callback 回調網址
 * @property int $status 狀態 0:建立,1:成功,2:失敗,3:己上分,4:待手動確認上分
 * @property string|null $extra_content 訂單額外內容
 * @property string $remark 備註
 * @property string|null $pay_time 支付時間
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Agent|null $agent
 * @property-read AgentCashFlow|null $agentCashFlow
 * @property-read Currency|null $currency
 * @property-read Member|null $member
 * @property-read Collection<int, OrderImage> $orderImages
 * @property-read int|null $order_images_count
 * @property-read OrderType|null $orderType
 * @property-read OrderTypeName|null $orderTypeName
 * @property-read mixed $owner
 *
 * @method static \Database\Factories\OrderFactory factory($count = null, $state = [])
 * @method static Builder<static>|Order newModelQuery()
 * @method static Builder<static>|Order newQuery()
 * @method static Builder<static>|Order query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(OrderObserver::class)]
class Order extends Model
{
    use HasFactory;
    use HasMember;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * 取得訂單下單者，需搭配 with(['agent', 'member']) 預載：
     * - agent_id > 0：三方代付訂單，下單者為 Agent
     * - agent_id = 0：一般會員提款，下單者為 Member
     */
    protected function owner(): Attribute
    {
        return Attribute::get(
            fn () => $this->agent_id > 0 ? $this->agent : $this->member
        );
    }

    public function agentCashFlow(): BelongsTo
    {
        return $this->belongsTo(AgentCashFlow::class);
    }

    public function orderImages(): HasMany
    {
        return $this->hasMany(OrderImage::class);
    }

    public function orderType(): BelongsTo
    {
        return $this->belongsTo(OrderType::class, 'order_type_id');
    }

    public function orderTypeName(): HasOne
    {
        return $this->hasOne(OrderTypeName::class, 'type_id', 'order_type_id');
    }

    /**
     * @deprecated DB 的 currency_id 不是對的, 固定是 1, 請改用 OrderType 的 currency 關聯
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }
}
