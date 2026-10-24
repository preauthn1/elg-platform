<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $operator_id OperatorId
 * @property string $type 注单類型
 * @property string $tran_id TranId
 * @property string $event_id BonusId / JackpotId
 * @property string $event_name BonusName
 * @property string $username PlayerId
 * @property numeric $payout_amount Payout
 * @property string $tran_date_time
 * @property string $session_token AuthToken
 * @property string $result Result
 * @property int $status 狀態 0:已處理,1:未處理
 * @property numeric $exchange_rate ExchangeRate
 * @property string $currency currency
 * @property string $info response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EpicSwEventRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EpicSwEventRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EpicSwEventRecord query()
 *
 * @mixin \Eloquent
 */
class EpicSwEventRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
