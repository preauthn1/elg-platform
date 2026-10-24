<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BindReward\BindRewardTypeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $reward_name 獎勵名稱
 * @property int $agent_id 代理 id
 * @property numeric $point 獎勵金額
 * @property BindRewardTypeEnum $type 獎勵類型, 1: 平台點數, 2: 禮碼
 * @property string|null $flow_limits 稽核流水設定
 * @property int $status 狀態, 1: 啟用, 2: 停用
 * @property string $start_time 開始時間
 * @property string $end_time 結束時間
 * @property Carbon $created_at 建立時間
 * @property Carbon $updated_at 更新時間
 *
 * @method static Builder<static>|BindReward newModelQuery()
 * @method static Builder<static>|BindReward newQuery()
 * @method static Builder<static>|BindReward query()
 *
 * @mixin \Eloquent
 */
class BindReward extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'type' => BindRewardTypeEnum::class,
    ];
}
