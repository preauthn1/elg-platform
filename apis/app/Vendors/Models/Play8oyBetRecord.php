<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $bet_id 注单唯一Id, ThemeId
 * @property string $username 玩家帳號
 * @property string $game_code 游戏ID
 * @property string $bet_time 押注時間(utc)
 * @property numeric $bet 下注金额; BetCoin
 * @property numeric $valid_bet 有效投注; AvailTotalBet
 * @property numeric $win 贏得金额; WinAmount
 * @property numeric $win_lose 輸贏金额; WinAmount - BetCoin
 * @property string $info response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Play8oyBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Play8oyBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Play8oyBetRecord query()
 *
 * @mixin \Eloquent
 */
class Play8oyBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = 'win';

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'updated_at';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';
}
