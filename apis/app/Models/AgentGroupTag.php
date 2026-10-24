<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_group_id 遊戲商群組ID(vendor_customize_group.id)
 * @property string $tag_name 玩家帳號
 * @property int $status 狀態 0:停用,1:使用中
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read Collection<int, AgentGroupGameTag> $agentGroupGameTags
 * @property-read int|null $agent_group_game_tags_count
 *
 * @method static Builder<static>|AgentGroupTag newModelQuery()
 * @method static Builder<static>|AgentGroupTag newQuery()
 * @method static Builder<static>|AgentGroupTag query()
 *
 * @mixin \Eloquent
 */
class AgentGroupTag extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function agentGroupGameTags()
    {
        return $this->hasMany(AgentGroupGameTag::class);
    }
}
