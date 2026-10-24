<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * App\Models\PtswBetLog
 *
 * @property int $id
 * @property string|null $request_id 三方請求 ID
 * @property string|null $username 三方玩家帳號
 * @property string|null $token token
 * @property string|null $bet_id 注單號碼
 * @property string|null $transaction_code 三方交易代號
 * @property string|null $type 三方交易行為
 * @property numeric $bet_amount 投注金額
 * @property numeric $win_amount 派彩金額
 * @property numeric $jackpot 彩池中獎金額
 * @property string|null $external_transaction_code 我司交易代號
 * @property string|null $external_transaction_date 我司交易時間
 * @property Carbon|null $applied_at
 * @property array<array-key, mixed>|null $raw 三方原始數據
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<static>|PtswDealRecord newModelQuery()
 * @method static Builder<static>|PtswDealRecord newQuery()
 * @method static Builder<static>|PtswDealRecord query()
 *
 * @mixin \Eloquent
 */
class PtswDealRecord extends Model
{
    protected ?string $vendorUsernameColumn = 'username';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'raw' => 'array',
    ];
}
