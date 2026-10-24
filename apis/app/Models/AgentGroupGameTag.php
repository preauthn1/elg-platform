<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_group_id 遊戲商群組ID(vendor_customize_groups.id)
 * @property int $game_id 遊戲ID(games.id)
 * @property int $agent_group_tag_id 遊戲商群組ID(agent_group_tags.id)
 * @property int $sort 排序
 * @property int $status 狀態 0:停用,1:使用中
 * @property Carbon $created_at 建立日期
 *
 * @method static Builder<static>|AgentGroupGameTag newModelQuery()
 * @method static Builder<static>|AgentGroupGameTag newQuery()
 * @method static Builder<static>|AgentGroupGameTag query()
 *
 * @mixin \Eloquent
 */
class AgentGroupGameTag extends Model
{
    // ! This table doesn't have updated_at column
    public const UPDATED_AT = null;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
