<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Vendors\SexySlotCq9SW\Enums\BetRecordStatusEnum;

/**
 * Round 層級彙總（一個 roundid 一列，bet/valid_bet/win_lose/rolling_chip 為即時累計的 running total）。
 *
 * @property int $id
 * @property string $bet_id 注單id（roundid）
 * @property string $username 玩家帳號
 * @property string $game_code 遊戲編號, gameId from request
 * @property BetRecordStatusEnum $type 該回合最後的事件
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 有效下注金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $rolling_chip 使用禮碼
 * @property numeric $bonus bonus
 * @property numeric $jackpot jackpot
 * @property string $bet_time 下注時間
 * @property string|null $payout_time 結算時間
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SexySlotCq9SwBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SexySlotCq9SwBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SexySlotCq9SwBetRecord query()
 *
 * @mixin \Eloquent
 */
class SexySlotCq9SwBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = 'rolling_chip';

    protected ?string $jackpotColumn = 'jackpot';

    protected ?string $bonusColumn = 'bonus';

    protected ?string $betIdColumn = 'bet_id';

    protected $table = 'sexyslotcq9sw_bet_records';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';

    protected $casts = [
        'type' => BetRecordStatusEnum::class,
    ];
}
