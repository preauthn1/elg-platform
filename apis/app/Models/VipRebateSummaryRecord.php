<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $summary_start_time 計算時間區間-開始
 * @property string $summary_end_time 計算時間區間-結束
 * @property int $member_id members.id
 * @property int $game_type_id game_types.id
 * @property numeric $bet 投注金額
 * @property numeric $proportion 返水比例
 * @property numeric $rebate_amount 返水金額
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<static>|VipRebateSummaryRecord newModelQuery()
 * @method static Builder<static>|VipRebateSummaryRecord newQuery()
 * @method static Builder<static>|VipRebateSummaryRecord query()
 *
 * @mixin \Eloquent
 */
class VipRebateSummaryRecord extends Model
{
    //
}
