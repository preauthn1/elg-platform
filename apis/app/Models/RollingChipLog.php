<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RollingChipLogTypeEnum;
use App\Observers\RollingChipLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_id
 * @property int $member_id
 * @property int $vendor_id
 * @property RollingChipLogTypeEnum $type
 * @property numeric $amount
 * @property numeric $before_balance
 * @property numeric $balance
 * @property int $status
 * @property string|null $remark
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 *
 * @method static Builder<static>|RollingChipLog newModelQuery()
 * @method static Builder<static>|RollingChipLog newQuery()
 * @method static Builder<static>|RollingChipLog query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(RollingChipLogObserver::class)]
class RollingChipLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'type' => RollingChipLogTypeEnum::class,
    ];
}
