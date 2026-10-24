<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property string $bet_id 交易 id, gameRoundId from request
 * @property string $ref_id 與遊戲商核對時的交易編號
 * @property string $game_round_id 遊戲回合id
 * @property string $username 玩家帳號
 * @property string $game_id 遊戲編號, gameId from request
 * @property int $status 1:debit,2:credit,3:rollback,4:resettlement
 * @property string $type 交易類型(細節查看遊戲商文件)
 * @property string $game_code
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 有效下注金額
 * @property numeric $payout 贏得金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $rolling_chips 使用禮碼
 * @property string $bet_time 下注時間
 * @property string|null $payout_time 派彩/取消/bonus時間
 * @property string $infos 詳細資訊
 * @property string|null $result_infos
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeswBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeswBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeswBetRecord query()
 *
 * @mixin \Eloquent
 */
class WeswBetRecord extends AbstractBetRecord
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
