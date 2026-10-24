<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 代理錢包（佔成錢包）異動紀錄。
 *
 * @property int $id
 * @property int $wallet_type_id 錢包類別ID
 * @property int $agent_id agents.id
 * @property int $type 類別（{@see \App\Enums\Transaction\WalletTransactionLogAgentTypeEnum}）
 * @property numeric $amount 金額
 * @property numeric $before_balance 變更前金額
 * @property numeric $balance 變更後金額
 * @property int $operator agents.id(操作人)
 * @property numeric $operator_before_balance 變更前金額(操作人)
 * @property numeric $operator_balance 變更後金額(操作人)
 * @property string $remark 備註
 * @property int $status 狀態; 0: 失敗, 1: 成功
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read Agent|null $agent
 * @property-read Agent|null $operatorAgent
 *
 * @method static Builder<static>|WalletTransactionLogAgent newModelQuery()
 * @method static Builder<static>|WalletTransactionLogAgent newQuery()
 * @method static Builder<static>|WalletTransactionLogAgent query()
 *
 * @mixin \Eloquent
 */
class WalletTransactionLogAgent extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function operatorAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'operator');
    }
}
