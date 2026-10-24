<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id 流水號
 * @property string $agent_group_id agent_groups.id
 * @property int $agent_id 代理id
 * @property string $ios_version ios版本
 * @property string|null $ios_link ios下載連結
 * @property string $android_version android版本
 * @property string|null $android_link android下載連結
 * @property int $type 類型; 1:真實版本號, 2:臨時版本號
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read Agent|null $agent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AgentDomain> $agentDomain
 * @property-read int|null $agent_domain_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Version newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Version newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Version query()
 *
 * @mixin \Eloquent
 */
class Version extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function agentDomain(): HasMany
    {
        return $this->hasMany(AgentDomain::class, 'agent_id', 'agent_id');
    }
}
