<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Missions\MissionIsClaimedEnum;
use App\Enums\Missions\MissionRewardTypeEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $mission_id
 * @property int $member_id
 * @property MissionRewardTypeEnum|null $reward_type 獎勵類型 MissionRewardTypeEnum
 * @property float|null $reward_value 獎勵值
 * @property string|null $date 日期
 * @property string|null $week 年週
 * @property string|null $month 月份
 * @property string|null $finish_time 完成時間
 * @property string|null $claim_time 領取時間
 * @property MissionIsClaimedEnum $is_claimed 是否領取
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MissionClaimRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MissionClaimRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MissionClaimRecord query()
 *
 * @mixin \Eloquent
 */
class MissionClaimRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'reward_type' => MissionRewardTypeEnum::class,
        'is_claimed' => MissionIsClaimedEnum::class,
    ];
}
