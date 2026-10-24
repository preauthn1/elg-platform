<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id PK流水號
 * @property int $member_id member_id
 * @property int $bank_id 關聯的銀行; order_types.id
 * @property string $account_name 帳戶名稱
 * @property string $account_no 帳戶帳號
 * @property int $status 狀態
 * @property int $sort 排序
 * @property int $operator_cs 修改人(客服後台)
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WithdrawalOrder> $withdrawalOrders
 * @property-read int|null $withdrawal_orders_count
 *
 * @method static Builder<static>|MemberBankAccount newModelQuery()
 * @method static Builder<static>|MemberBankAccount newQuery()
 * @method static Builder<static>|MemberBankAccount query()
 *
 * @mixin \Eloquent
 */
class MemberBankAccount extends Model
{
    public $rules_create = [
        'account' => 'required',
    ];

    public $rules_update = [
        'account' => 'required',
    ];

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 出款订单关联
     *
     * 通过 member_id 和 bank_id 关联出款订单
     */
    public function withdrawalOrders(): HasMany
    {
        return $this->hasMany(WithdrawalOrder::class, 'bank_id', 'bank_id')
            ->where('withdrawal_orders.member_id', $this->member_id);
    }
}
