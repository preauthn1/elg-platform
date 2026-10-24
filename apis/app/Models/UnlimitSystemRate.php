<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id 流水號
 * @property int $agent_id 代理ID
 * @property int $agent_group_id 無限代群組ID
 * @property int $level 無限代理等級
 * @property numeric $min_amount 該級距金額下限
 * @property numeric $max_amount 該級距金額上限
 * @property numeric $bonus_amount 該級距獎勵金額
 * @property numeric $rebate_rate 返傭比例
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnlimitSystemRate newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnlimitSystemRate newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnlimitSystemRate query()
 *
 * @mixin \Eloquent
 */
class UnlimitSystemRate extends Model
{
    protected $table = 'unlimit_systemrate';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
