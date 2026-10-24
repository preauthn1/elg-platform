<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $from_agent_id 發送者的代理系統編號
 * @property int $to_agent_id 接收者的代理系統編號
 * @property int $type 類型1:開分, 2:洗分, 3:代理模式獎勵(全民代理三層返水), 4:代理傭金派發, 5:分桶獎勵
 * @property int $order_id withdrawal_order_agents.id
 * @property numeric $amount 金額
 * @property numeric $rolling_chip_amount 禮碼
 * @property numeric $from_limit_of_credit 發送者剩餘額度
 * @property numeric $from_used_credit 發送者已使用額度
 * @property numeric $to_limit_of_credit 接收者剩餘額度
 * @property numeric $to_used_credit 接收者已使用額度
 * @property string|null $operator 實際操作者(有可能是子帳號)
 * @property int $platform 操作平台1:客服，2:代理後台
 * @property string|null $remark 備註
 * @property int $status 狀態0:失敗，1:成功
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read Agent|null $fromAgent
 * @property-read Agent|null $toAgent
 *
 * @method static Builder<static>|TransactionLogAgent newModelQuery()
 * @method static Builder<static>|TransactionLogAgent newQuery()
 * @method static Builder<static>|TransactionLogAgent query()
 *
 * @mixin \Eloquent
 */
class TransactionLogAgent extends Model
{
    /** operator：系統排程產生（無人工操作者） */
    public const string OPERATOR_SYSTEM = 'system';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function fromAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'from_agent_id');
    }

    public function toAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'to_agent_id');
    }
}
