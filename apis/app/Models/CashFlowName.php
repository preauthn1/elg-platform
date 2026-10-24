<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $language_id 語言系統編號
 * @property int $cash_flow_id 金流類型系統編號
 * @property int $agent_group_id 群組編號
 * @property string $name 金流類型名稱
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|CashFlowName newModelQuery()
 * @method static Builder<static>|CashFlowName newQuery()
 * @method static Builder<static>|CashFlowName query()
 *
 * @mixin \Eloquent
 */
class CashFlowName extends Model
{
    protected $guarded = [
        'created_at',
        'updated_at',
    ];
}
