<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id 系統編號
 * @property string $rowID 遊戲紀錄詳情ID
 * @property string $playerName 商戶用戶名稱
 * @property string $gameDate 遊戲時間
 * @property string $gameCode 遊戲代碼
 * @property int $gameType 遊戲類型
 * @property numeric $betAmount 下注金額
 * @property numeric|null $winAmount 贏得金額
 * @property numeric $commissionable 有效投注量
 * @property numeric $roomFee 房費
 * @property numeric $income 赢得金額-下注金額-房費
 * @property numeric|null $progressive_wins 貢獻彩金
 * @property numeric|null $progressive_share 獲得彩金
 * @property int $merchantId 商戶ID
 * @property string|null $utc_gameDate 遊戲時間(UTC+0)
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MtBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MtBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MtBetRecord query()
 *
 * @mixin \Eloquent
 */
class MtBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'betAmount';

    protected ?string $validBetColumn = null;

    // winAmount 為贏得金額，輸贏無單一欄位
    protected ?string $winLoseColumn = null;

    protected ?string $winColumn = 'winAmount';

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'rowID';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = null;

    protected ?string $betTimeColumn = 'utc_gameDate';

    protected ?string $vendorUsernameColumn = 'playerName';
}
