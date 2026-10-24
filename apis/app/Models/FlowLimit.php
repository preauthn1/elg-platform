<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id 玩家ID
 * @property int $type 參照類型: 1:transaction_logs
 * @property int|null $event_id
 * @property int $reference_id 參照ID; 依照type
 * @property int $wallet_transaction_log_id wallet_transaction_logs.id
 * @property numeric $flow_limit 流水限制
 * @property numeric $reach 達成
 * @property string|null $remark 備註
 * @property int $status 狀態; 0:未通過, 1:通過, 9:取消稽核
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|FlowLimit newModelQuery()
 * @method static Builder<static>|FlowLimit newQuery()
 * @method static Builder<static>|FlowLimit query()
 *
 * @mixin \Eloquent
 */
class FlowLimit extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
