<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $order_no 訂單單號
 * @property int $type
 * @property int $member_id 玩家編號
 * @property numeric $withdraw_point 提線金額
 * @property numeric $remaining_point 剩餘金額
 * @property int $status 狀態:0失敗,1成功
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|ThreeHierarchyWithdrawLog newModelQuery()
 * @method static Builder<static>|ThreeHierarchyWithdrawLog newQuery()
 * @method static Builder<static>|ThreeHierarchyWithdrawLog query()
 *
 * @mixin \Eloquent
 */
class ThreeHierarchyWithdrawLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
