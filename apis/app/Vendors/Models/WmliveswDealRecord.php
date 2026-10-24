<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Models\Member;
use App\Models\Traits\HasMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * App\Models\WmliveswDealRecord
 *
 * @property int $id
 * @property string|null $deal_id dealid 交易單號
 * @property string|null $deal_code code 加扣点类型 0:电子游戏结算 1:加点 2:扣点 3:重对加点 4:重对扣点 5:重新派彩
 * @property string $member_id 會員帳號 ID
 * @property string $account_id 遊戲帳號 ID
 * @property string $username 遊戲帳號
 * @property string $amount money 加(扣)该会员的点数
 * @property string $game_code gtype 遊戲類別編號
 * @property string|null $bet_id 注單編號
 * @property string $request_time requestDate 三方請求時間
 * @property int $status 我方處理狀態, 0:未處理, 1:交易成功, 2:交易失敗(餘額不足)
 * @property string|null $transaction_log_id 交易紀錄編號
 * @property int $is_settlement 0:未結算, 1:結算
 * @property int $is_rollback 0:未回滾, 1:已回滾
 * @property numeric $rolling_chips 使用禮碼
 * @property string|null $raw_data 三方原始數據
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Member|null $member
 *
 * @method static Builder<static>|WmliveswDealRecord newModelQuery()
 * @method static Builder<static>|WmliveswDealRecord newQuery()
 * @method static Builder<static>|WmliveswDealRecord query()
 *
 * @mixin \Eloquent
 */
class WmliveswDealRecord extends Model
{
    use HasMember;

    protected ?string $vendorUsernameColumn = 'username';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
