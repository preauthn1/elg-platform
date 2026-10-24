<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Models\Traits\HasMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property int $member_id 會員編號
 * @property string $username 玩家帳號
 * @property string $bet_id 注單號 roundId
 * @property string $game_code 遊戲代碼
 * @property numeric $bet 投注額
 * @property numeric $valid_bet 有效投注額
 * @property numeric $win 派彩金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $bonus 額外獎金
 * @property numeric $jackpot 彩池獎金
 * @property numeric $rolling_chip 投注禮碼金額
 * @property Carbon $bet_time 投注時間
 * @property Carbon|null $payout_time 結算時間
 * @property int $status 狀態 0:建立注單,1:進行中,2:退款,3:已結算
 * @property string|null $info
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|SexyslotswBetRecord newModelQuery()
 * @method static Builder<static>|SexyslotswBetRecord newQuery()
 * @method static Builder<static>|SexyslotswBetRecord query()
 *
 * @mixin \Eloquent
 */
class SexyslotswBetRecord extends AbstractBetRecord
{
    use HasMember;

    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = 'win';

    protected ?string $rollingChipColumn = 'rolling_chip';

    protected ?string $jackpotColumn = 'jackpot';

    protected ?string $bonusColumn = 'bonus';

    protected ?string $betIdColumn = 'bet_id';

    protected $table = 'sexyslotsw_bet_records';

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
