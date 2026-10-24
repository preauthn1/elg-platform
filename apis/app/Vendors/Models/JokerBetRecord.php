<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id 流水號
 * @property string $app_id APP編號
 * @property string $transaction_type 注單類型; Game or Jackpot
 * @property string $bet_id 投注編號唯一值
 * @property string $game_type 遊戲類型; wager/endWager/cancelWager/cancelEndWager/appendWagerResult/promopayout/campaign/campaignpayout
 * @property string $username 玩家帳號
 * @property string $game_code 遊戲代碼
 * @property numeric $bet_amount 投注金額
 * @property numeric $win_amount 贏分金額
 * @property string $bet_time 投注時間
 * @property string $info response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JokerBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JokerBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JokerBetRecord query()
 *
 * @mixin \Eloquent
 */
class JokerBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet_amount';

    protected ?string $validBetColumn = null;

    protected ?string $winLoseColumn = 'win_amount';

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
