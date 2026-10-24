<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id 會員編號
 * @property string $username 玩家帳號 playerId
 * @property string $bet_id 注單號 betId
 * @property string $game_code 遊戲ID
 * @property numeric $bet 投注額
 * @property numeric $valid_bet 有效投注額
 * @property numeric $payout 派彩金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $rolling_chips 投注禮碼金額
 * @property string $bet_time 投注時間
 * @property string|null $payout_time 結算時間
 * @property int $status 狀態 1:已下注(BET) 2:已結算(PAID) 3:已退款(REFOUND)，見 App\Vendors\AFBSW\BetRecordStatusEnum
 * @property array<array-key, mixed>|null $info 注單信息
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|AfbswBetRecord newModelQuery()
 * @method static Builder<static>|AfbswBetRecord newQuery()
 * @method static Builder<static>|AfbswBetRecord query()
 *
 * @mixin \Eloquent
 */
class AfbswBetRecord extends AbstractBetRecord
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

    protected $casts = [
        'info' => 'array',
    ];
}
