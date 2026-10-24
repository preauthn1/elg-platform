<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id 流水號
 * @property string $playerID 玩家編號
 * @property string $extPlayerID 玩家編號
 * @property string $gameID 遊戲編號
 * @property string $transactionType 交易類型(BT:Bet, TU:Top up, WD:Withdraw, JP:Jackpot)
 * @property string $referenceID 交易編號(唯一值)
 * @property string $status 狀態C-Completed
 * @property string $created 投注時間
 * @property string $updated 投注更新時間
 * @property numeric $betAmount 投注金額
 * @property numeric $winAmount 輸贏金額
 * @property string $jackpotModule 彩金模式
 * @property numeric $jackpotContributionAmt 彩金金額
 * @property string $currency 幣種
 * @property string $resultUrl 開獎結果url
 * @property string $roundDetails 詳情
 * @property string $platform 遊玩平台
 * @property string|null $info info
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ace333BetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ace333BetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ace333BetRecord query()
 *
 * @mixin \Eloquent
 */
class Ace333BetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'betAmount';

    protected ?string $validBetColumn = null;

    // 輸贏由 winAmount - betAmount 計算得出
    protected ?string $winLoseColumn = null;

    protected ?string $winColumn = 'winAmount';

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'referenceID';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = null;

    protected ?string $betTimeColumn = 'created';

    protected ?string $vendorUsernameColumn = 'playerID';
}
