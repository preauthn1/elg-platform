<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property string $bet_id 注单唯一Id, ThemeId
 * @property string $username 玩家帳號
 * @property string $game_code 游戏ID
 * @property string $bet_time 投注時間(utc)
 * @property numeric $bet 下注金额
 * @property numeric $win_lose 輸贏金额
 * @property string $info response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|C93BetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|C93BetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|C93BetRecord query()
 *
 * @mixin \Eloquent
 */
class C93BetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = null;

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = null;

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';
}
