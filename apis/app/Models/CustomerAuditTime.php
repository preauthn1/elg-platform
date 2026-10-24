<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_id 訂單編號
 * @property int $cash_flow_id 金流資料編號
 * @property int $type 1:審核 2:通過
 * @property string $operator_time 操作時間
 * @property int $operator 操作人
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|CustomerAuditTime newModelQuery()
 * @method static Builder<static>|CustomerAuditTime newQuery()
 * @method static Builder<static>|CustomerAuditTime query()
 *
 * @mixin \Eloquent
 */
class CustomerAuditTime extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
