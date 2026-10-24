<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Models\Member;
use App\Models\Traits\HasMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * App\Models\PtswBetRecord
 *
 * @property int $id
 * @property string $member_id 我方會員ID
 * @property string $username 三方玩家帳號
 * @property string|null $token 外部令牌，用於標識玩家的遊戲週期。必須包含kiosk 前綴。
 * @property string|null $bet_id 注單號碼
 * @property string|null $transaction_code 三方交易代號
 * @property string $game_code 遊戲代碼
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 有效下注金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $rolling_chips 使用禮碼
 * @property numeric $jackpot 獎池彩金
 * @property string|null $bet_time 下注時間
 * @property string|null $payout_time 派彩時間
 * @property int $status 處理狀態: -2=回滾, -1=餘額不足, 0=待處理, 1=遊戲中, 2=遊戲回合結束
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read PtswAccount $account
 * @property-read Collection<int, PtswDealRecord> $dealRecords
 * @property-read int|null $deal_records_count
 * @property-read Member|null $member
 *
 * @method static Builder<static>|PtswBetRecord newModelQuery()
 * @method static Builder<static>|PtswBetRecord newQuery()
 * @method static Builder<static>|PtswBetRecord query()
 *
 * @mixin \Eloquent
 */
class PtswBetRecord extends AbstractBetRecord
{
    use HasMember;

    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = 'rolling_chips';

    protected ?string $jackpotColumn = 'jackpot';

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

    protected array $dates = [
        'bet_time',
        'payout_time',
    ];

    /**
     * 關聯: ptsw_accounts 遊戲帳號
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PtswAccount::class, 'username', 'username');
    }

    /**
     * 關聯: ptsw_deal_records 交易紀錄
     */
    public function dealRecords(): HasMany
    {
        return $this->hasMany(PtswDealRecord::class, 'bet_id', 'bet_id');
    }
}
