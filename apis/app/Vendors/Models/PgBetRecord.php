<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id 系統編號
 * @property string $TransactionId 遊戲紀錄詳情ID
 * @property string $ProductGroup 產品組別
 * @property string $ExternalUserId 外部遊戲名稱
 * @property string $GamesessionId 遊戲sessionID
 * @property string $GameId 遊戲ID
 * @property string $Currency 幣別
 * @property string $ExternalTransactionId 外部遊戲詳情ID
 * @property numeric $Balance 錢包金額
 * @property string $NumRounds 次數
 * @property numeric $TotalLoss 投注金額
 * @property numeric $TotalGain 贏得金額
 * @property string $GamesessionStarted 遊戲開始時間
 * @property string $GamesessionFinished 遊戲結束時間
 * @property numeric $ExchangeRate 匯率
 * @property string|null $ContextId 開始遊戲時指定的上下文ID
 * @property string|null $ClientIP 客戶IP
 * @property string|null $ExternalFreegameId 外部免費遊戲ID
 * @property numeric $JackpotLoss 貢獻彩金
 * @property numeric $JackpotGain 獲得彩金
 * @property numeric|null $FreegameRounds 免費遊戲次數
 * @property numeric|null $FreegameBet 免費遊戲投注金額
 * @property numeric|null $FreegameWin 免費遊戲贏得金額
 * @property string|null $Channel 頻道
 * @property string $MessageId 訊息ID
 * @property string $MessageTimestamp 獲得訊息時間
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PgBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PgBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PgBetRecord query()
 *
 * @mixin \Eloquent
 */
class PgBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'TotalLoss';

    protected ?string $validBetColumn = null;

    // TotalGain 為贏得金額，輸贏無單一欄位
    protected ?string $winLoseColumn = null;

    protected ?string $winColumn = 'TotalGain';

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = null;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'GamesessionFinished';

    protected ?string $betTimeColumn = 'GamesessionStarted';

    protected ?string $vendorUsernameColumn = 'ExternalUserId';
}
