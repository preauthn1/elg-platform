<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id 會員系統編號
 * @property numeric $rebate_amount 自身返利
 * @property numeric $down_rebate_amount 下層返利
 * @property numeric $down2_rebate_amount 下下層返利
 * @property numeric $bonus_amount 總獎金
 * @property numeric $withdraw_amount 已提領獎金
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|ThreeHierarchySummaryBonus newModelQuery()
 * @method static Builder<static>|ThreeHierarchySummaryBonus newQuery()
 * @method static Builder<static>|ThreeHierarchySummaryBonus query()
 *
 * @mixin \Eloquent
 */
class ThreeHierarchySummaryBonus extends Model
{
    protected $table = 'three_hierarchy_summary_bonus';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
