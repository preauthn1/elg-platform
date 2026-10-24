<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property string $bet_id 注單唯一編號
 * @property string $username 玩家帳號
 * @property string $player_id 三方帳號
 * @property string $game_code 遊戲代碼
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 有效投注
 * @property numeric $win 派彩金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $jackpot jackpot金額
 * @property string $bet_time 下注時間
 * @property string|null $payout_time 派彩/取消/bonus時間
 * @property int $status 結算狀態
 * @property string|null $info 詳細資訊
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OppoBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OppoBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OppoBetRecord query()
 *
 * @mixin \Eloquent
 */
class OppoBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = 'win';

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = 'jackpot';

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
