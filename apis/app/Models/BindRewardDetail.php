<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $bind_reward_id 綁定獎勵 id
 * @property int|null $grand_agent_id 總代理 id
 * @property string $phone 手機號碼, 國碼+手機號碼
 * @property int $status 狀態, 0: 未綁定, 1: 已綁定
 * @property int|null $member_id 會員 id
 * @property string|null $ip IP
 * @property string|null $bind_time 註冊時間
 * @property Carbon $created_at 建立時間
 * @property Carbon $updated_at 更新時間
 * @property-read BindReward $bindReward
 *
 * @method static Builder<static>|BindRewardDetail newModelQuery()
 * @method static Builder<static>|BindRewardDetail newQuery()
 * @method static Builder<static>|BindRewardDetail query()
 *
 * @mixin \Eloquent
 */
class BindRewardDetail extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function bindReward(): BelongsTo
    {
        return $this->belongsTo(BindReward::class, 'bind_reward_id', 'id');
    }
}
