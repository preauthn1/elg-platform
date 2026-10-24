<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 會員統計雜項（一會員一筆）：最後活動/存提/投注時間、登入與密碼相關錯誤累計次數。
 *
 * @property int $id 流水號
 * @property int $member_id 會員系統編號
 * @property Carbon|null $last_activity_at 最後請求API時間
 * @property Carbon|null $last_deposit_at 最後代收成功時間
 * @property Carbon|null $last_withdraw_at 最後代付成功時間
 * @property Carbon|null $last_bet_at 最後投注時間
 * @property int $total_login_fail_count 總登入錯誤次數
 * @property int $login_fail_count 登入錯誤次數
 * @property int $forget_pwd_count 忘記密碼次數
 * @property int|null $payment_pwd_fail_count 支付密碼錯誤次數
 * @property int|null $validation_code_fail_count 驗證碼錯誤次數
 * @property int|null $google_auth_fail_count Google驗證錯誤次數
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read Member|null $member
 *
 * @mixin \Eloquent
 */
class MemberStatistic extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'last_deposit_at' => 'datetime',
        'last_withdraw_at' => 'datetime',
        'last_bet_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
