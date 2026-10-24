<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $summary_time 數據時間
 * @property int $agent_id 代理編號
 * @property int $event_id 事件編號; 1:活動獎勵(含登入活動), 2:無限代獎勵,3:pay fee, 4:sms fee
 * @property numeric|null $point 點數(不含代理本身)
 * @property numeric|null $self_point 代理本身所獲得點數
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|SummaryEventRecordAgent newModelQuery()
 * @method static Builder<static>|SummaryEventRecordAgent newQuery()
 * @method static Builder<static>|SummaryEventRecordAgent query()
 *
 * @mixin \Eloquent
 */
class SummaryEventRecordAgent extends Model
{
    protected $primaryKey = null;

    public $incrementing = false;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
