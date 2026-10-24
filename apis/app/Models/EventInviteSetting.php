<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_id 代理系統編號
 * @property numeric $max_reward 單筆最高獎勵
 * @property numeric $realname_reward 實名認證獎勵
 * @property numeric $deposit_point 儲值門檻點數
 * @property numeric $deposit_reward 儲值達標獎勵
 * @property numeric $bet_point 打碼門檻點數
 * @property numeric $bet_reward 打碼達標獎勵
 * @property int $block_type 封鎖類型
 * @property bool $ip_block 是否啟用IP封鎖
 * @property bool $mobile_block 是否啟用手機號封鎖
 * @property bool $realname_block 是否啟用實名封鎖
 * @property int $sort 前端排序
 * @property int $status 狀態
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $admin
 * @property-read \Illuminate\Support\Collection<int, EventInviteExcludeAgent> $excludeAgents
 *
 * @method static Builder<static>|EventInviteSetting newModelQuery()
 * @method static Builder<static>|EventInviteSetting newQuery()
 * @method static Builder<static>|EventInviteSetting query()
 *
 * @mixin \Eloquent
 */
class EventInviteSetting extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function excludeAgents(): HasMany
    {
        return $this->hasMany(EventInviteExcludeAgent::class);
    }
}
