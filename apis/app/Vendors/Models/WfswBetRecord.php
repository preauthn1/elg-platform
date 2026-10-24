<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property string $bet_id 交易 id, gameRoundId from request
 * @property string|null $result_id 交易 id, gameRoundId from request
 * @property string $username 玩家帳號
 * @property string $game_code 遊戲編號, gameId from request
 * @property int $status 1:bet,2:result,3:rollback,4:bouns,5:jackpot
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 真實下注金額
 * @property numeric $payout 贏得金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $rolling_chips 使用禮碼
 * @property string $bet_time 下注時間
 * @property string $payout_time 派彩/取消/bonus時間
 * @property string|null $infos 詳細資訊
 * @property string $result_infos
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WfswBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WfswBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WfswBetRecord query()
 *
 * @mixin \Eloquent
 */
class WfswBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = 'payout';

    protected ?string $rollingChipColumn = 'rolling_chips';

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [
        'infos',
        'result_infos',
        'created_at',
        'updated_at',
    ];
}
