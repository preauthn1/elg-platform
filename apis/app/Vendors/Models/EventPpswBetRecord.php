<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Models\Traits\HasMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_vendor_info_id 活動遊戲商設定編號
 * @property int $member_id 會員編號
 * @property string $username 玩家帳號 playerId
 * @property string $bet_id 注單號 roundId
 * @property string $reference_no 交易號
 * @property string $game_code 游戏ID
 * @property numeric $bet 投注額
 * @property numeric $valid_bet 有效投注額
 * @property numeric $win 派彩金额
 * @property numeric $win_lose 輸贏金额
 * @property numeric $bonus 額外獎金/錦標賽獎金
 * @property numeric $jackpot 彩池獎金
 * @property Carbon $bet_time 投注時間
 * @property Carbon|null $payout_time 結算時間
 * @property int $status 狀態 0:建立注單,1:進行中,2:退款,3:已結算
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \App\Models\Member|null $member
 *
 * @method static Builder<static>|EventPpswBetRecord newModelQuery()
 * @method static Builder<static>|EventPpswBetRecord newQuery()
 * @method static Builder<static>|EventPpswBetRecord query()
 *
 * @mixin \Eloquent
 */
class EventPpswBetRecord extends AbstractBetRecord
{
    use HasMember;

    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = 'win';

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = 'jackpot';

    protected ?string $bonusColumn = 'bonus';

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
        'bet_time' => 'datetime',
        'payout_time' => 'datetime',
    ];
}
