<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Models\Member;
use App\Models\Traits\HasMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * App\Models\WmliveswBetRecord
 *
 * @property int $id
 * @property string|null $bet_id 注单号
 * @property string $member_id 會員帳號 ID
 * @property string $account_id 遊戲帳號 ID
 * @property string $username 遊戲帳號
 * @property string $game_code gid 遊戲類別編號
 * @property numeric $bet_amount bet 下注金額
 * @property numeric $valid_bet validbet 有效下注金額
 * @property numeric $win_lose winLoss 輸贏金額
 * @property numeric $rolling_chips 使用禮碼
 * @property string $bet_deal_id 下注 deal_id (多筆)
 * @property string $result_deal_id 派彩 deal_id (輸錢無派彩返回 -1)
 * @property string|null $bet_time betTime 下注時間
 * @property string|null $settlement_time settime 結算時間
 * @property int $status 我方處理狀態, 0:未處理, 1:完成, 2:失敗
 * @property string|null $raw_data 三方原始數據
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read WmliveswAccount|null $account
 * @property-read Member|null $member
 *
 * @method static Builder<static>|WmliveswBetRecord newModelQuery()
 * @method static Builder<static>|WmliveswBetRecord newQuery()
 * @method static Builder<static>|WmliveswBetRecord query()
 *
 * @mixin \Eloquent
 */
class WmliveswBetRecord extends AbstractBetRecord
{
    use HasMember;

    protected ?string $betColumn = 'bet_amount';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = 'rolling_chips';

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected ?string $payoutTimeColumn = 'settlement_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 關聯的 wmlive_accounts 帳號
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(WmliveswAccount::class, 'user', 'user');
    }
}
