<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $bet_id 注单唯一Id
 * @property string $username 玩家帳號
 * @property string $game_code 游戏桌号
 * @property string $bet_time 押注時間(utc)
 * @property string $created_time 注單時間(utc)
 * @property numeric $bet 下注金额
 * @property numeric $win 贏得金额
 * @property numeric $refund 退還金额
 * @property numeric $win_lose 輸贏金额
 * @property numeric $jackpot jackpot
 * @property string $info response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sky1388BetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sky1388BetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sky1388BetRecord query()
 *
 * @mixin \Eloquent
 */
class Sky1388BetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = null;

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = 'win';

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = 'jackpot';

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
