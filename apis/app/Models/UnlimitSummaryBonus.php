<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id 玩家編號
 * @property numeric $performance_amount 業績金額
 * @property numeric $bonus_amount 未提領獎金
 * @property numeric $withdraw_amount 已提領獎金
 * @property numeric $flow_amount 總流水
 * @property numeric $itself_bonus_amount 自身回水獎金
 * @property numeric $itself_withdraw_amount 已提領自身回水獎金
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|UnlimitSummaryBonus newModelQuery()
 * @method static Builder<static>|UnlimitSummaryBonus newQuery()
 * @method static Builder<static>|UnlimitSummaryBonus query()
 *
 * @mixin \Eloquent
 */
class UnlimitSummaryBonus extends Model
{
    //
    protected $table = 'unlimit_summary_bonus';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
