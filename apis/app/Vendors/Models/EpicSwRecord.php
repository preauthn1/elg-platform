<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $operator_id OperatorId
 * @property string $type 注单類型
 * @property int $bet_id bet_id / tran_id
 * @property string $username PlayerId
 * @property string $game_code GameCode
 * @property int $round_id RoundId
 * @property string $bonus_id BonusId /
 * @property numeric $bet_amount BetAmount
 * @property numeric $rolling_chip_amount 禮碼
 * @property numeric|null $valid_bet_amount ValidBetAmount
 * @property numeric|null $payout_amount Payout
 * @property numeric|null $win_lose_amount WinLose
 * @property string $tran_date_time
 * @property string $session_token AuthToken
 * @property int|null $result_id ResultId
 * @property int $result_type 狀態 0:Single/Quick Game Round Result,1:Single/Quick Game Refund
 * @property string $result Result
 * @property int $status 狀態 0:已處理,1:未處理
 * @property string $currency currency
 * @property numeric $exchange_rate ExchangeRate
 * @property string $info response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EpicSwRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EpicSwRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EpicSwRecord query()
 *
 * @mixin \Eloquent
 */
class EpicSwRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';

    public $rules_balance = [
        'OperatorId' => 'required|between:0,20',
        'RequestDateTime' => 'required|between:0,50',
        'Signature' => 'required|between:0,50',
        'PlayerId' => 'required|between:0,50',
        'Currency' => 'required|between:0,5',
        'AuthToken' => 'required|between:0,500',
    ];

    public $rules_bet = [
        'OperatorId' => 'required|between:1,20',
        'RequestDateTime' => 'required|date_format:Y-m-d H:i:s',
        'Signature' => 'required|between:1,50',
        'PlayerId' => 'required|between:8,50',
        'Currency' => 'required|between:2,5',
        'RoundId' => 'required|digits_between:1,19',
        'BetId' => 'required|digits_between:1,19',
        'GameCode' => 'required|between:1,50',
        'GameType' => 'required|between:1,50',
        'BetAmount' => 'required',
        'ExchangeRate' => 'required',
        'TranDateTime' => 'required|date_format:Y-m-d H:i:s',
        'AuthToken' => 'required|between:1,500',
    ];

    public $rules_game_result = [
        'OperatorId' => 'required|between:1,20',
        'RequestDateTime' => 'required|date_format:Y-m-d H:i:s',
        'Signature' => 'required|between:1,50',
        'PlayerId' => 'required|between:8,50',
        'Currency' => 'required|between:2,5',
        'ResultId' => 'required|digits_between:1,19',
        'BetId' => 'required|digits_between:1,19',
        'RoundId' => 'required|digits_between:1,19',
        'GameCode' => 'required|between:1,50',
        'GameType' => 'required|between:1,50',
        'GameName' => 'required|between:1,50',
        'ResultType' => 'required|integer',
        'BetAmount' => 'required',
        'ValidBetAmount' => 'required',
        'Payout' => 'required',
        'WinLose' => 'required',
        'ExchangeRate' => 'required',
        'TranDateTime' => 'required|date_format:Y-m-d H:i:s',
    ];

    public $rules_rollback = [
        'OperatorId' => 'required|between:1,20',
        'RequestDateTime' => 'required|date_format:Y-m-d H:i:s',
        'Signature' => 'required|between:1,50',
        'PlayerId' => 'required|between:8,50',
        'Currency' => 'required|between:2,5',
        'RoundId' => 'required|digits_between:1,19',
        'BetId' => 'required|digits_between:1,19',
        'GameCode' => 'required|between:1,50',
        'GameType' => 'required|between:1,50',
        'BetAmount' => 'required',
        'ExchangeRate' => 'required',
        'TranDateTime' => 'required|date_format:Y-m-d H:i:s',
        'RollbackType' => 'required|integer',
    ];

    public $rules_cash_bonus = [
        'OperatorId' => 'required|between:1,20',
        'RequestDateTime' => 'required|date_format:Y-m-d H:i:s',
        'Signature' => 'required|between:1,50',
        'PlayerId' => 'required|between:8,50',
        'Currency' => 'required|between:2,5',
        'TranId' => 'required|between:1,25',
        'BonusId' => 'required|between:1,50',
        'BonusName' => 'required|between:0,50',
        'Payout' => 'required',
        'ExchangeRate' => 'required',
        'TranDateTime' => 'required|date_format:Y-m-d H:i:s',
        'Result' => 'required|between:1,100',
    ];

    public $rules_jackpot = [
        'OperatorId' => 'required|between:1,20',
        'RequestDateTime' => 'required|date_format:Y-m-d H:i:s',
        'Signature' => 'required|between:1,50',
        'PlayerId' => 'required|between:8,50',
        'Currency' => 'required|between:2,5',
        'TranId' => 'required|between:1,25',
        'JackpotId' => 'required|between:1,50',
        'Payout' => 'required',
        'ExchangeRate' => 'required',
        'TranDateTime' => 'required|date_format:Y-m-d H:i:s',
        'Result' => 'required|between:1,100',
    ];
}
