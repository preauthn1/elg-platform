<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $cash_flow_id cash_flows.Id
 * @property string|null $info API使用資訊
 * @property int $status 設置啟用狀態 0:停用, 1:啟用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 *
 * @method static Builder<static>|CashFlowInfo newModelQuery()
 * @method static Builder<static>|CashFlowInfo newQuery()
 * @method static Builder<static>|CashFlowInfo query()
 *
 * @mixin \Eloquent
 */
class CashFlowInfo extends Model
{
    protected $guarded = [
        'created_at',
        'updated_at',
    ];
}
