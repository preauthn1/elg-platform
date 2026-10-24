<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\General\ContextKeyEnum;
use App\Enums\LanguageEnum;
use App\Observers\AgentCashFlowOrderTypeObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;

/**
 * @property int $id
 * @property int $cash_flow_id 金流商編號（denormalize agent_cash_flows.cash_flow_id）
 * @property int $agent_cash_flow_id 金流商戶資料編號
 * @property int $order_type_id 支付方式編號
 * @property int $min_amount 下限: 0為不限制
 * @property int $max_amount 上限: 0為不限制
 * @property numeric $tax 手續費
 * @property numeric $tax_percentage 手續費(百分比)
 * @property string $available_amount 固額: 150,300,500
 * @property string $settings Settings JSON
 * @property int $sorts 排序權重
 * @property int $status 設置啟用狀態 0:停用, 1:啟用
 * @property numeric $cost 費率數值（搭配 cost_type 使用）
 * @property int $cost_type 費率類型：1=百分比, 2=固定金額
 * @property int $backend_display 後台顯示 0:隱藏, 1:顯示
 * @property int $is_recommend 是否推薦
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property Carbon|null $deleted_at 軟刪除時間；NULL=有效（隨所屬 ACF 連動軟刪／還原）
 * @property int|null $is_live 唯一性輔助欄：live=1、軟刪=NULL（generated column，勿寫入）
 * @property-read AgentCashFlow|null $agentCashFlow
 * @property-read OrderType|null $orderType
 * @property-read CashFlow|null $cashFlow
 * @property-read Collection<int, OrderTypeName> $agentCashFlowOrderTypeNames
 * @property-read int|null $agent_cash_flow_order_type_names_count
 * @property-read string $name 顯示名稱（Context 語系 → EN → order_types.name）
 *
 * @method static Builder<static>|AgentCashFlowOrderType newModelQuery()
 * @method static Builder<static>|AgentCashFlowOrderType newQuery()
 * @method static Builder<static>|AgentCashFlowOrderType query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(AgentCashFlowOrderTypeObserver::class)]
class AgentCashFlowOrderType extends Model
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

    public function orderType(): BelongsTo
    {
        return $this->belongsTo(OrderType::class, 'order_type_id', 'id');
    }

    public function agentCashFlow(): BelongsTo
    {
        return $this->belongsTo(AgentCashFlow::class, 'agent_cash_flow_id', 'id');
    }

    /**
     * 金流商（走 denorm cash_flow_id；由 Observer 守恆 acf.cash_flow_id == ot.cash_flow_id）
     */
    public function cashFlow(): BelongsTo
    {
        return $this->belongsTo(CashFlow::class, 'cash_flow_id', 'id');
    }

    /**
     * 多語系名稱（order_type_names 以 order_type_id 對 type_id；
     * 取代 OrderType::orderTypeNames 作為渠道查詢的名稱入口，舊關聯保留相容）
     */
    public function agentCashFlowOrderTypeNames(): HasMany
    {
        return $this->hasMany(OrderTypeName::class, 'type_id', 'order_type_id');
    }

    /**
     * 顯示名稱：Context 注入語系 → EN → order_types.name
     */
    protected function name(): Attribute
    {
        return Attribute::get(function (): string {
            $languageId = (int) Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
            $names = $this->agentCashFlowOrderTypeNames;

            return $names->firstWhere('language_id', $languageId)?->name
                ?? $names->firstWhere('language_id', LanguageEnum::EN->value)?->name
                ?? (string) $this->orderType?->name;
        })->shouldCache();
    }
}
