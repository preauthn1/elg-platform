<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property string $bet_id 注单唯一Id, ThemeId
 * @property string $username 玩家帳號
 * @property string $game_code 游戏ID
 * @property string $SDate 算帳時間
 * @property string $bet_time 派彩時間(utc)
 * @property string $created_time 押注時間(utc)
 * @property int $bet_status
 * @property int|null $ticket_result
 * @property numeric $bet 下注金额
 * @property numeric|null $valid_bet 有效投注金額
 * @property numeric $win_lose 輸贏金额
 * @property string $info response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Obet33BetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Obet33BetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Obet33BetRecord query()
 *
 * @mixin \Eloquent
 */
class Obet33BetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected ?string $payoutTimeColumn = 'bet_time';

    protected ?string $betTimeColumn = 'created_time';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
