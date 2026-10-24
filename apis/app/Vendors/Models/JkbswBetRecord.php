<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property int $member_id 會員系統編號
 * @property string $username Joker playerID
 * @property string $bet_id Joker roundID（同 round 多筆 transaction 共用）
 * @property string $game_code 桌台代碼 tableCode
 * @property numeric $bet 累計投注金額（同 round 多筆 action=2 累加）
 * @property numeric $valid_bet 累計有效投注 validTurnover
 * @property numeric $win 累計派彩金額（同 round 多筆 action=3 累加）
 * @property numeric $win_lose 輸贏 = win - bet
 * @property numeric $bonus 活動獎金
 * @property numeric $jackpot Jackpot 獎金
 * @property numeric $rolling_chip 禮碼金額
 * @property string|null $bet_time 首次 action=2 寫入
 * @property string|null $payout_time 最近一次 action=3 寫入
 * @property int $status 狀態 0:建立,1:已結算,-1:已退款
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, JkbswDealRecord> $dealRecords
 * @property-read int|null $deal_records_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JkbswBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JkbswBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JkbswBetRecord query()
 *
 * @mixin \Eloquent
 */
class JkbswBetRecord extends AbstractBetRecord
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

    protected $table = 'jkbsw_bet_records';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function dealRecords(): HasMany
    {
        return $this->hasMany(JkbswDealRecord::class, 'bet_record_id');
    }
}
