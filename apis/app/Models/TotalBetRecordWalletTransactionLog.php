<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $transaction_log_id wallet_transaction_logs.id
 * @property int $total_bet_record_id total_bet_records.id
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|TotalBetRecordWalletTransactionLog newModelQuery()
 * @method static Builder<static>|TotalBetRecordWalletTransactionLog newQuery()
 * @method static Builder<static>|TotalBetRecordWalletTransactionLog query()
 *
 * @mixin \Eloquent
 */
class TotalBetRecordWalletTransactionLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
