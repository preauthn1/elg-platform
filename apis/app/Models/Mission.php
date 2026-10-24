<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Missions\MissionActionTypeEnum;
use App\Enums\Missions\MissionResetTypeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property int $agent_id 代理ID
 * @property int $status 0: never started, 1: active, 2: ended, -1: inactive
 * @property MissionResetTypeEnum $reset_type 0: daily, 1: weekly, 2: monthly
 * @property MissionActionTypeEnum $action_type 操作類型, 0: deposit, 1: bet, 2: game
 * @property string $condition_type
 * @property numeric $condition_value
 * @property string $description
 * @property string $start_time
 * @property string $end_time
 * @property array<array-key, mixed>|null $info 任務資訊, all setting infos like condition, reward, etc.
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $admin
 * @property-read Agent|null $agent
 * @property-read Collection<int, Agent> $availableAgents
 * @property-read int|null $available_agents_count
 * @property-read Collection<int, MemberMission> $memberMissions
 * @property-read int|null $member_missions_count
 *
 * @method static \Database\Factories\MissionFactory factory($count = null, $state = [])
 * @method static Builder<static>|Mission newModelQuery()
 * @method static Builder<static>|Mission newQuery()
 * @method static Builder<static>|Mission query()
 *
 * @mixin \Eloquent
 */
class Mission extends Model
{
    use HasFactory;

    protected $table = 'missions';

    protected $primaryKey = 'id';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'info' => 'array',
        'action_type' => MissionActionTypeEnum::class,
        'reset_type' => MissionResetTypeEnum::class,
    ];

    protected $hidden = ['pivot'];

    public function memberMissions(): HasMany
    {
        return $this->hasMany(MemberMission::class, 'mission_id', 'id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }

    public function availableAgents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'mission_available_agents', 'mission_id', 'agent_id');
    }
}
