<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property int $member_id 會員編號
 * @property string $username 玩家帳號 playerId
 * @property string $bet_id 注單號 betId
 * @property string $reference_no
 * @property string $game_code 游戏ID
 * @property string|null $draw_type
 * @property string|null $draw_name
 * @property string|null $op_code 商戶 OP_CODE
 * @property numeric $bet 投注額 betAmount
 * @property numeric $valid_bet 有效投注額 realBetAmount
 * @property numeric $win 派彩金额
 * @property numeric $win_lose 輸贏金额
 * @property numeric $bonus 額外獎金/錦標賽獎金
 * @property numeric $jackpot 彩池獎金
 * @property numeric $rolling_chip 投注禮碼金額
 * @property Carbon $bet_time 投注時間
 * @property Carbon|null $payout_time 結算時間
 * @property int $status 狀態 0:建立注單,1:進行中,2:退款,3:已結算
 * @property string|null $remark
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|Nex4dswBetRecord newModelQuery()
 * @method static Builder<static>|Nex4dswBetRecord newQuery()
 * @method static Builder<static>|Nex4dswBetRecord query()
 *
 * @mixin \Eloquent
 */
class Nex4dswBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = 'win';

    protected ?string $rollingChipColumn = 'rolling_chip';

    protected ?string $jackpotColumn = 'jackpot';

    protected ?string $bonusColumn = 'bonus';

    // 歷史樣板誤置：本表唯一交易鍵存於 reference_no（bet_id 為回合/非唯一），getBetId() 供 TBR.reference_no 與 log 使用
    protected ?string $betIdColumn = 'reference_no';

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';

    // protected ?string $payoutTimeColumn = 'payout_time';
    // protected ?string $betTimeColumn = 'bet_time';
    // protected ?string $vendorUsernameColumn = 'username';

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
