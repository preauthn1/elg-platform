<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * CosmoPlay SW 注單記錄（Seamless 單一錢包）。
 *
 * 每一個 Spin 對應一筆注單：
 *   - bet-result  → 建立 status=0（投注中），扣除餘額
 *   - win-result  → 更新 status=1（已結算），加入贏分
 *   - cancel-result → 更新 status=2（已取消），退還注額
 *
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property int $member_id 會員系統編號
 * @property string $bet_id 注單唯一識別 SpinID
 * @property string $round_id 遊戲回合 RoundID
 * @property string $username 玩家帳號
 * @property string $game_code 遊戲代碼 GameID
 * @property string $bet_time 投注時間
 * @property string|null $payout_time 派彩時間
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 有效投注
 * @property numeric $win 派彩金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $rolling_chip 禮碼金額
 * @property int $is_round_end 是否為該回合最後一次 Spin
 * @property int $status 注單狀態 0:投注中 1:已結算 2:已取消
 * @property string $info 原始請求資料
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|CosmoPlaySWBetRecord newModelQuery()
 * @method static Builder<static>|CosmoPlaySWBetRecord newQuery()
 * @method static Builder<static>|CosmoPlaySWBetRecord query()
 *
 * @mixin \Eloquent
 */
class CosmoPlaySWBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = 'win';

    protected ?string $rollingChipColumn = 'rolling_chip';

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected $table = 'cosmoplaysw_bet_records';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';
}
