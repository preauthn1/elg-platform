<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property string|null $third_party_code 三方代碼; 如果是聚合金流商, 就用同一個代碼
 * @property string $name 金流商名稱
 * @property numeric $rate
 * @property numeric $percentage
 * @property int $type 支付類別; 1.線上支付, 2線下支付, 3.代付, 4.自助洗分
 * @property int $status 狀態0:停用,1:啟用
 * @property string|null $remark
 * @property array<array-key, mixed>|null $info
 * @property string|null $whitelist 回調來源 IP 白名單; ip1,ip2,ip3,...（比照 vendors.whitelist）
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Collection<int, AgentCashFlow> $agentCashFlows
 * @property-read int|null $agent_cash_flows_count
 * @property-read Collection<int, CashFlowName> $names
 * @property-read int|null $names_count
 * @property-read Collection<int, OrderType> $orderTypes
 * @property-read int|null $order_types_count
 *
 * @method static Builder<static>|CashFlow newModelQuery()
 * @method static Builder<static>|CashFlow newQuery()
 * @method static Builder<static>|CashFlow query()
 *
 * @mixin \Eloquent
 */
class CashFlow extends Model
{
    protected $guarded = [
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'info' => 'array',
    ];

    public function names(): HasMany
    {
        return $this->hasMany(CashFlowName::class, 'cash_flow_id', 'id');
    }

    public function agentCashFlows(): HasMany
    {
        return $this->hasMany(AgentCashFlow::class);
    }

    public function orderTypes(): HasMany
    {
        return $this->hasMany(OrderType::class, 'cash_flow_id');
    }
}
