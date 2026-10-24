<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Helpers\Common;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * TTHSW Seamless Wallet 投注記錄
 *
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property string $username 玩家帳號
 * @property string $bet_id 注單唯一ID
 * @property string $game_code 遊戲代碼
 * @property numeric $bet 投注額
 * @property numeric $valid_bet 有效投注額
 * @property numeric $win 派彩金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $bonus 額外獎金
 * @property numeric $jackpot 彩池獎金
 * @property numeric $rolling_chip 投注禮碼金額
 * @property numeric $reserve_amount 預留金額
 * @property numeric $reserve_used 已使用的預留金額
 * @property string $bet_time 投注時間
 * @property string|null $payout_time 結算時間
 * @property int $status 狀態 0:建立注單,1:進行中,2:退款,3:已結算
 * @property string|null $info Response data
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|TTHSWBetRecord newModelQuery()
 * @method static Builder<static>|TTHSWBetRecord newQuery()
 * @method static Builder<static>|TTHSWBetRecord query()
 *
 * @mixin \Eloquent
 */
class TTHSWBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = 'win';

    protected ?string $rollingChipColumn = 'rolling_chip';

    protected ?string $jackpotColumn = 'jackpot';

    protected ?string $bonusColumn = 'bonus';

    protected ?string $betIdColumn = 'bet_id';

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';

    protected $table = 'tthsw_bet_records';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 取得遊戲回合的 Reserve 金額
     */
    public function getReserveAmount(): string
    {
        return $this->reserve_amount ?? '0.0000';
    }

    /**
     * 取得已使用的 Reserve 金額
     */
    public function getReserveUsed(): string
    {
        return $this->reserve_used ?? '0.0000';
    }

    /**
     * 取得剩餘的 Reserve 金額
     */
    public function getRemainingReserve(): string
    {
        $reserved = $this->getReserveAmount();
        $used = $this->getReserveUsed();

        return Common::numSub($reserved, $used, 4);
    }

    /**
     * 檢查是否為 Reserve
     */
    public function isReserve(): bool
    {
        return Common::numCompare($this->getReserveAmount(), '0') > 0;
    }
}
