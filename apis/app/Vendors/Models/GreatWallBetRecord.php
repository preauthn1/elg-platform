<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id 流水號
 * @property int $themeId 前四碼遊戲ID，後四碼大廳ID
 * @property string|null $gameId 遊戲ID, 參照themeId
 * @property string|null $gameNo 遊戲編號
 * @property string|null $gameSerialId 遊戲流水號
 * @property string|null $accountId 玩家ID
 * @property string|null $userName 玩家帳號
 * @property numeric|null $valueBefore 投注前金額
 * @property numeric|null $valueAfter 投注後金額
 * @property numeric|null $betCoin 投注金額
 * @property numeric|null $winAmount 贏得金額
 * @property numeric|null $availTotalBet 有效投注金額
 * @property numeric|null $availTotalWin 有效贏得金額
 * @property string|null $remoteIP 玩家IP
 * @property int|null $deviceType 設備編號
 * @property string|null $historyCreatedTime 遊戲記錄建立時間
 * @property string|null $utc_created_time 遊戲記錄建立UTC時間
 * @property string|null $info
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GreatWallBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GreatWallBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GreatWallBetRecord query()
 *
 * @mixin \Eloquent
 */
class GreatWallBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'betCoin';

    protected ?string $validBetColumn = 'availTotalBet';

    // availTotalWin 為贏得金額，輸贏無單一欄位
    protected ?string $winLoseColumn = null;

    protected ?string $winColumn = 'winAmount';

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'gameSerialId';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = null;

    protected ?string $betTimeColumn = 'utc_created_time';

    protected ?string $vendorUsernameColumn = 'accountId';
}
