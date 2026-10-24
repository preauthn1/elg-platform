<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id 流水號
 * @property int $member_id 玩家編號
 * @property string $summary_date 時間(日)
 * @property numeric $performance 業績-自己
 * @property numeric $performance_direct 業績-直屬
 * @property numeric $performance_team 業績-團隊
 * @property numeric $bonus_itself 獎金-自身回水
 * @property numeric $bonus_direct 獎金-直屬
 * @property numeric $bonus_team 獎金-團隊
 * @property numeric $bonus_amount 該級距獎勵金額-參考unlimit_systemrate
 * @property int $correction 0:否, 1:是
 * @property string|null $memo 備註
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int $admin 更新者ID
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnlimitBonusReport newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnlimitBonusReport newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnlimitBonusReport query()
 *
 * @mixin \Eloquent
 */
class UnlimitBonusReport extends Model
{
    //
    protected $table = 'unlimit_bonusreport';

    protected $casts = [
        'correction' => 'integer',
    ];
}
