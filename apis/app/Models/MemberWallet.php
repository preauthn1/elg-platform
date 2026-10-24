<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WalletEnum;
use App\Models\Traits\HasMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id 玩家帳號
 * @property WalletEnum $wallet_type_id 錢包類型（決議⑬三分法：1 一般／3 活動／1002 禮碼為保留類型；4 積分為非貨幣、經 MemberScoreService 加減；2、1004 已廢除存量待清；1003 非貨幣、2026-09-03 已裁決廢除但前置未完成）
 * @property int $currency_id 幣別 currencies.id；非貨幣錢包固定 0
 * @property numeric $balance 錢包實際金額
 * @property string|null $first_deposit 首次儲值時間
 * @property int $is_bet 當天是否投注過
 * @property int $status 帳號狀態 0:未使用,1:使用中,2:鎖定(異常, 無法使用)
 * @property string|null $memo 方便技術記錄用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者
 * @property-read Member|null $member
 *
 * @method static \Database\Factories\MemberWalletFactory factory($count = null, $state = [])
 * @method static Builder<static>|MemberWallet newModelQuery()
 * @method static Builder<static>|MemberWallet newQuery()
 * @method static Builder<static>|MemberWallet query()
 *
 * @mixin \Eloquent
 */
class MemberWallet extends Model
{
    use HasFactory;
    use HasMember;

    public bool $lockBalance = false;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'wallet_type_id' => WalletEnum::class,
    ];

    public function setBalanceAttribute($value)
    {
        if ($this->lockBalance) {
            return;
        }

        $this->attributes['balance'] = $value;
    }
}
