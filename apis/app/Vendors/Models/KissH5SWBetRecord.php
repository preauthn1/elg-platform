<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * KissH5 注單記錄。
 *
 * ⚠️  時區提醒（區分兩個層次）：
 *   - 單筆時間（bet_time / payout_time）：本表 = UTC，KissH5 callback 的 TranDateTime 也 = UTC，直接存即可，不需換算
 *   - 後台「日界」：KissH5 後台、CSV 匯出、GetDaySummary 都以 UTC+8（SGT）為日邊界
 *     對帳或聚合查詢時，若要對齊 KissH5 的「2026-05-01」，UTC 區間應為 [2026-04-30 16:00, 2026-05-01 16:00)
 *   - 直接把 UTC 整日當成 KissH5 的「日」會在個別玩家對帳產生 8 小時錯位的假差異
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
 * @method static Builder<static>|KissH5SWBetRecord newModelQuery()
 * @method static Builder<static>|KissH5SWBetRecord newQuery()
 * @method static Builder<static>|KissH5SWBetRecord query()
 *
 * @mixin \Eloquent
 */
class KissH5SWBetRecord extends AbstractBetRecord
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

    protected $table = 'kissh5sw_bet_records';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';
}
