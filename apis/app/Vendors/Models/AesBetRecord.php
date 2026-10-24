<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id 系統編號
 * @property string $gameType 遊戲類別
 * @property numeric $winAmount 贏得金額
 * @property string $bet_time 投注時間
 * @property string $settleStatus
 * @property string $gameInfo 遊戲資訊
 * @property numeric $realWinAmount 實際贏得金額
 * @property numeric $realBetAmount 實際投注金額
 * @property string $updateTime 更新注單時間
 * @property string $userId 玩家名稱
 * @property string $betType 投注類別
 * @property string $platform 平台
 * @property string $txStatus 狀態
 * @property numeric $betAmount 投注金額
 * @property string $platformTxId
 * @property string $gameCode 遊戲編號
 * @property string $currency 幣別
 * @property numeric $jackpotWinAmount 彩池贏得金額
 * @property numeric $jackpotBetAmount 彩池投注金額
 * @property string $bet_id ID
 * @property string $turnover
 * @property string $roundId
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AesBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AesBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AesBetRecord query()
 *
 * @mixin \Eloquent
 */
class AesBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'betAmount';

    protected ?string $validBetColumn = null;

    // winAmount 為贏得金額，輸贏無單一欄位
    protected ?string $winLoseColumn = null;

    protected ?string $winColumn = 'winAmount';

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

    protected ?string $vendorUsernameColumn = 'userId';
}
