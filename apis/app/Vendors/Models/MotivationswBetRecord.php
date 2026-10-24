<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Vendors\MotivationSW\Enums\BetRecordStatusEnum;

/**
 * @property int $id
 * @property string $bet_id 注單id
 * @property string $username 玩家帳號
 * @property string $game_code 遊戲編號, gameId from request
 * @property string $mtcode mtcode
 * @property BetRecordStatusEnum $status 1:Bet, 2:EndRound, 3:Rollout, 4:Rollin, 5:Refund
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 有效下注金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $rolling_chips 使用禮碼
 * @property string $bet_time 下注時間
 * @property string|null $result_date 算結果的日期時間
 * @property string|null $infos 詳細資訊
 * @property string|null $result_infos result 詳細資訊
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MotivationswBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MotivationswBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MotivationswBetRecord query()
 *
 * @mixin \Eloquent
 */
class MotivationswBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = 'rolling_chips';

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'result_date';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';

    protected $casts = [
        'status' => BetRecordStatusEnum::class,
    ];
}
