<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Models\Member;
use App\Models\Traits\HasMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * App\Models\FffswBetRecord
 *
 * @property int $id
 * @property string|null $bet_id 注單號碼
 * @property string|null $period_number 期號
 * @property string $member_id 我方會員ID
 * @property string $account_id 三方帳號 ID
 * @property string $username 三方玩家帳號
 * @property string $game_code 遊戲代碼
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 有效下注金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $rolling_chips 使用禮碼
 * @property string|null $bet_time 下注時間
 * @property string|null $payout_time 派彩時間
 * @property int $status 處理狀態: -2=回滾, -1=餘額不足, 0=未完成, 1=已扣款, 2=已派彩
 * @property array<array-key, mixed>|null $raw 三方原始數據
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read FffswAccount|null $account
 * @property-read Member|null $member
 *
 * @method static Builder<static>|FffswBetRecord newModelQuery()
 * @method static Builder<static>|FffswBetRecord newQuery()
 * @method static Builder<static>|FffswBetRecord query()
 *
 * @mixin \Eloquent
 */
class FffswBetRecord extends AbstractBetRecord
{
    use HasMember;

    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = 'rolling_chips';

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';

    protected $dates = [
        'bet_time',
        'payout_time',
    ];

    protected $casts = [
        'raw' => 'array',
    ];

    /**
     * 關聯的 wmlive_accounts 帳號
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(FffswAccount::class, 'user', 'user');
    }
}
