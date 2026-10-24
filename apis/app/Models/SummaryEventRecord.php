<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $summary_time 數據時間
 * @property int $member_id 會員編號
 * @property int $event_id 事件編號; 1:活動獎勵(含登入活動), 2:無限代獎勵, 3:支付成本, 4:SMS成本
 * @property numeric|null $point 點數
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|SummaryEventRecord newModelQuery()
 * @method static Builder<static>|SummaryEventRecord newQuery()
 * @method static Builder<static>|SummaryEventRecord query()
 *
 * @mixin \Eloquent
 */
class SummaryEventRecord extends Model
{
    protected $primaryKey = null;

    public $incrementing = false;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
