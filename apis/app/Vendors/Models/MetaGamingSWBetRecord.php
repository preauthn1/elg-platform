<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * MTG (Meta Gaming) 注單記錄。
 *
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property int $member_id 會員系統編號
 * @property string $type N:normal, B:bonus, J:jackpot
 * @property string|null $round_id 遊戲回合Id（RoundId）
 * @property string $bet_id 注單唯一Id（N:BetId, B:BonusId, J:JackpotId）
 * @property string $reference_no 三方訂單號（N:ResultId, B/J:TranId）
 * @property string $username 玩家帳號
 * @property string $game_code 遊戲代碼
 * @property string $bet_time 投注時間(UTC)
 * @property string|null $payout_time 派彩時間(UTC)
 * @property numeric $bet 下注金額
 * @property numeric|null $valid_bet 有效投注
 * @property numeric $win 派彩金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $rolling_chip 禮碼金額
 * @property numeric|null $bonus 活動獎金
 * @property numeric|null $jackpot Jackpot 獎金
 * @property int $status 注單狀態 0:建立中 1:結算/派彩 2:回滾
 * @property string $info 原始請求資料
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|MetaGamingSWBetRecord newModelQuery()
 * @method static Builder<static>|MetaGamingSWBetRecord newQuery()
 * @method static Builder<static>|MetaGamingSWBetRecord query()
 *
 * @mixin \Eloquent
 */
class MetaGamingSWBetRecord extends AbstractBetRecord
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

    protected $table = 'metagamingsw_bet_records';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';
}
