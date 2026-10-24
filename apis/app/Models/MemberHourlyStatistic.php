<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 會員每小時存提匯總（一會員一小時一筆）。
 * deposit_*／withdrawal_* 為總額；六類來源明細見 {@see \App\Enums\Statistics\MemberHourlySourceEnum}，
 * 三類充值相加＝deposit_*、三類提現相加＝withdrawal_*（target/tickets#4333）。
 *
 * @property int $id 流水號
 * @property int $member_id 會員系統編號
 * @property string $summary_hour 統計整點（該小時起點）
 * @property int $deposit_count 該小時代收成功次數
 * @property numeric $deposit_amount 該小時代收成功總額（orders.point 加總）
 * @property int $withdrawal_count 該小時代付成功次數
 * @property numeric $withdrawal_amount 該小時代付成功總額（withdrawal_orders.point 加總）
 * @property int $third_party_deposit_count 三方充值次數（orders 成功、金流商非 AutoDeposit）
 * @property numeric $third_party_deposit_amount 三方充值總額
 * @property int $auto_deposit_count 自助上分次數（orders 成功、金流商 AutoDeposit）
 * @property numeric $auto_deposit_amount 自助上分總額
 * @property int $admin_deposit_count 後台開分次數（transaction_logs type=4、order_id=0）
 * @property numeric $admin_deposit_amount 後台開分總額
 * @property int $third_party_withdrawal_count 三方提現次數（withdrawal_orders status=24）
 * @property numeric $third_party_withdrawal_amount 三方提現總額
 * @property int $auto_withdrawal_count 自助洗分次數（withdrawal_orders status=4）
 * @property numeric $auto_withdrawal_amount 自助洗分總額
 * @property int $admin_withdrawal_count 後台洗分次數（transaction_logs type=5、order_id=0）
 * @property numeric $admin_withdrawal_amount 後台洗分總額
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read Member|null $member
 *
 * @mixin \Eloquent
 */
class MemberHourlyStatistic extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
