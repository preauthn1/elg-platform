<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * TTHSW Seamless Wallet 交易記錄
 *
 * @property int $id
 * @property string|null $game_code 遊戲代碼
 * @property string $game_round_id Round Id
 * @property string $transaction_id Transaction Id
 * @property string $transaction_date TransactionDate
 * @property numeric $amount 金額
 * @property numeric $start_balance 交易前餘額
 * @property numeric $end_balance 交易後餘額
 * @property int $type Type
 * @property string $action 請求動作
 * @property string|null $info Response data
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|TTHSWDealRecord newModelQuery()
 * @method static Builder<static>|TTHSWDealRecord newQuery()
 * @method static Builder<static>|TTHSWDealRecord query()
 *
 * @mixin \Eloquent
 */
class TTHSWDealRecord extends AbstractBetRecord
{
    protected $table = 'tthsw_deal_records';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
