<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property string $type N:normal, B:bonus, J:jackpot
 * @property string $bet_id 注单唯一Id, ThemeId
 * @property int $result_id
 * @property string $username 玩家帳號
 * @property string $game_code 游戏ID
 * @property string $bet_time 投注時間(utc)
 * @property string $payout_time 派彩時間(UTC)
 * @property numeric $bet 下注金额
 * @property numeric|null $valid_bet 有效投注
 * @property numeric $win_lose 輸贏金额
 * @property numeric $rolling_chip 禮碼金额
 * @property numeric|null $bonus bonus/jackpot
 * @property int $status 注單狀態 0: 取消 1:成功
 * @property string $info response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EpicSwBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EpicSwBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EpicSwBetRecord query()
 *
 * @mixin \Eloquent
 */
class EpicSwBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = 'rolling_chip';

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = 'bonus';

    protected ?string $betIdColumn = 'bet_id';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';
}
