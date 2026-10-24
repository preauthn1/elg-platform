<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 銀行帳戶異動紀錄（自助上分／自助洗分對應的收付款帳戶）。
 *
 * @property int $id
 * @property int $order_id source=3 時為 orders.id、source=4 時為 withdrawal_orders.id
 * @property int $account_id bank_accounts.id
 * @property int $source 來源（{@see \App\Enums\CashFlow\BankTransactionLogSourceEnum}）
 * @property int $member_id 會員系統編號
 * @property numeric $point 金额
 * @property numeric $before_amount 改動前金额
 * @property string|null $remark 備註
 * @property string $operator 實際操作者
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read BankAccount|null $bankAccount
 *
 * @method static Builder<static>|BankTransactionLog newModelQuery()
 * @method static Builder<static>|BankTransactionLog newQuery()
 * @method static Builder<static>|BankTransactionLog query()
 *
 * @mixin \Eloquent
 */
class BankTransactionLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'account_id');
    }
}
