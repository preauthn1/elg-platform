<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_group_id 代理群組編號
 * @property numeric $limit 限額
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read AgentGroup|null $agentGroups
 *
 * @method static Builder<static>|TransferLimit newModelQuery()
 * @method static Builder<static>|TransferLimit newQuery()
 * @method static Builder<static>|TransferLimit query()
 *
 * @mixin \Eloquent
 */
class TransferLimit extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function agentGroups(): BelongsTo
    {
        return $this->belongsTo(AgentGroup::class, 'agent_group_id');
    }
}
