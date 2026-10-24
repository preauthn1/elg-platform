<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $order_no 訂單單號
 * @property int $member_id 玩家編號
 * @property numeric $reward_point 申請前結算金額
 * @property numeric $withdrawn_point 已提領金額
 * @property numeric $amount 提領金額
 * @property numeric $itself_reward_point 申請前自身回水結算金額
 * @property numeric $itself_withdrawn_point 已提領自身回水金額
 * @property numeric $itself_amount 提領自身回水金額
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int $type 支付類型。 0:未發放, 1:已發放、2:拒絕
 * @property string|null $remark 備註
 * @property int|null $admin 操作人員
 *
 * @method static Builder<static>|UnlimitWithdrawLog newModelQuery()
 * @method static Builder<static>|UnlimitWithdrawLog newQuery()
 * @method static Builder<static>|UnlimitWithdrawLog query()
 *
 * @mixin \Eloquent
 */
class UnlimitWithdrawLog extends Model
{
    //
    protected $table = 'unlimit_withdrawlog';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public $rules_search = [
        'member_id' => 'required|domain',
    ];

    public $rules_create = [
        'amount' => 'required|numeric|min:0.01',
    ];
}
