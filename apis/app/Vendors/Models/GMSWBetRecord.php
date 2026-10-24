<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property int $member_id 會員系統編號
 * @property string $username GM uname
 * @property string $bet_id GM session_id（round 唯一鍵）
 * @property string $game_code 遊戲代碼
 * @property numeric $bet 累計投注（type=1 累加）
 * @property numeric $valid_bet 累計有效投注
 * @property numeric $win 累計派彩（type=3 累加）
 * @property numeric $win_lose 輸贏 = win - bet
 * @property numeric $bonus 活動獎金
 * @property numeric $jackpot Jackpot 獎金
 * @property numeric $rolling_chip 禮碼金額
 * @property string|null $bet_time 首次 type=1 投注時間
 * @property string|null $payout_time 最近一次 type=3 派彩時間
 * @property int $status 0:進行中, 1:已結算（type=4）, -1:已取消（type=2）
 * @property array<array-key, mixed>|null $info
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, GMSWDealRecord> $dealRecords
 * @property-read int|null $deal_records_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GMSWBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GMSWBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GMSWBetRecord query()
 *
 * @mixin \Eloquent
 */
class GMSWBetRecord extends AbstractBetRecord
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

    protected $table = 'gmsw_bet_records';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'info' => 'array',
    ];

    public function dealRecords(): HasMany
    {
        return $this->hasMany(GMSWDealRecord::class, 'bet_record_id');
    }
}
