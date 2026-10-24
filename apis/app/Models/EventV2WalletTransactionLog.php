<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $wallet_transaction_log_id wallet_transaction_logs.id
 * @property int|null $vendor_id 遊戲商id
 * @property int|null $account_id 遊戲帳號id
 * @property string|null $account_username 遊戲帳號名稱
 * @property int|null $event_v2_user_join_id 參加活動id
 * @property int|null $operate_agent_id 操作代理id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|EventV2WalletTransactionLog newModelQuery()
 * @method static Builder<static>|EventV2WalletTransactionLog newQuery()
 * @method static Builder<static>|EventV2WalletTransactionLog query()
 *
 * @mixin \Eloquent
 */
class EventV2WalletTransactionLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
