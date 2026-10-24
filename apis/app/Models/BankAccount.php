<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_type_id 銀行id
 * @property string $account_name 帳戶名稱
 * @property string|null $account_nickname 帳戶簡稱
 * @property string $account_no 銀行帳號
 * @property int $is_deposit 是否可以開分
 * @property int $is_withdrawal 是否可以洗分
 * @property numeric $rate 匯率
 * @property numeric $principal 銀行本金
 * @property numeric $amount 銀行金額
 * @property string|null $qrcode QR Code
 * @property numeric $min_amount 帳戶名稱
 * @property numeric $max_amount 帳戶名稱
 * @property int $use_times 使用次數
 * @property int $status 狀態
 * @property numeric $amount_threshold 通知金額門檻
 * @property int $count_threshold 通知次數門檻
 * @property string|null $last_activated_at 最後啟用時間
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read OrderType|null $orderType
 *
 * @method static \Database\Factories\BankAccountFactory factory($count = null, $state = [])
 * @method static Builder<static>|BankAccount newModelQuery()
 * @method static Builder<static>|BankAccount newQuery()
 * @method static Builder<static>|BankAccount query()
 *
 * @mixin \Eloquent
 */
class BankAccount extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function orderType(): BelongsTo
    {
        return $this->belongsTo(OrderType::class);
    }
}
