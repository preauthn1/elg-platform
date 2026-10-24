<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property string $bet_id 注单唯一Id, ThemeId
 * @property string $session_token 訂單號的session_token
 * @property string $username 玩家帳號
 * @property string $game_code 游戏ID
 * @property string $bet_time 投注時間(utc)
 * @property string $payout_time 派彩時間(utc)
 * @property string $settlement_time 結算時間
 * @property numeric $bet 下注金额
 * @property numeric $win 派彩金额
 * @property numeric $win_lose 輸贏金额
 * @property numeric $rolling_chip 禮碼金額
 * @property int $status 狀態 0:未結算,1:已結算
 * @property string $info response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PgsswBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PgsswBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PgsswBetRecord query()
 *
 * @mixin \Eloquent
 */
class PgsswBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = null;

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = 'win';

    protected ?string $rollingChipColumn = 'rolling_chip';

    protected ?string $jackpotColumn = null;

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
