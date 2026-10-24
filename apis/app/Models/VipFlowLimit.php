<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $type
 * @property int $member_id 會員系統編號
 * @property numeric $deposit_amount 充值金額
 * @property numeric $flow_limit 稽核流水金額
 * @property numeric $reach 達成金額
 * @property string|null $remark 達成金額
 * @property int $status 狀態 0:未達成 1:已達成
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|VipFlowLimit newModelQuery()
 * @method static Builder<static>|VipFlowLimit newQuery()
 * @method static Builder<static>|VipFlowLimit query()
 *
 * @mixin \Eloquent
 */
class VipFlowLimit extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
