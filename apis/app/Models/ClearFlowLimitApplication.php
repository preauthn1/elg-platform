<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id members.id
 * @property int $flow_limit_id flow_limits.id
 * @property int $status 狀態; 0: 待審核, 1: 成功, 2:拒絕
 * @property string|null $remark 備注
 * @property int $operator agents.id
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|ClearFlowLimitApplication newModelQuery()
 * @method static Builder<static>|ClearFlowLimitApplication newQuery()
 * @method static Builder<static>|ClearFlowLimitApplication query()
 *
 * @mixin \Eloquent
 */
class ClearFlowLimitApplication extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
