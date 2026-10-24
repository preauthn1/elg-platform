<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $agent_id
 * @property string $type
 * @property string $reference_id
 * @property string $reference_type
 * @property string $debug_info
 * @property int|null $used_ip
 * @property string $ip
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property-read Agent|null $agent
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentOperateLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentOperateLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentOperateLog query()
 *
 * @mixin \Eloquent
 */
class AgentOperateLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }
}
