<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property string $bet_id 注單唯一Id, ThemeId
 * @property string $username 玩家帳號
 * @property string $game_code 遊戲 ID
 * @property string $bet_time 投注時間(UTC)
 * @property string $payout_time 派彩、結算時間(UTC)
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 有效投注金額
 * @property numeric $win_lose 輸贏金額
 * @property string $info response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lfc888BetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lfc888BetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lfc888BetRecord query()
 *
 * @mixin \Eloquent
 */
class Lfc888BetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

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

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';
}
