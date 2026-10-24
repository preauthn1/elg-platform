<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id 流水號
 * @property int|null $agent_id 代理系統編號(agents)
 * @property int $type 推廣碼類型; 0:一般, 1:佔成
 * @property string $code 推廣碼
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property-read Agent|null $agent
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentInviteCode newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentInviteCode newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentInviteCode query()
 *
 * @mixin \Eloquent
 */
class AgentInviteCode extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 關聯的 agent 帳號
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }
}
