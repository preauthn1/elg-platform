<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id PK流水號
 * @property int $agent_group_type 1:基本個性化設置組別、2:財務組別  3:簡訊設置組別 4.聊天chatGroup相關
 * @property int $organization_id 所屬組織
 * @property string $name 備註顯示名稱(需要多語系嗎)
 * @property string $memo 備註
 * @property int $is_default 是否為預設組別
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read Collection<int, Agent> $agents
 * @property-read int|null $agents_count
 *
 * @method static Builder<static>|AgentGroup newModelQuery()
 * @method static Builder<static>|AgentGroup newQuery()
 * @method static Builder<static>|AgentGroup query()
 *
 * @mixin \Eloquent
 */
class AgentGroup extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class, 'vendor_customize_group_id');
    }
}
