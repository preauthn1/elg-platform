<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 系統編號
 * @property int $agent_id 代理系統編號(Agent)
 * @property int $agent_menu_id 代理後台選單系統編號(AgentMenu)
 * @property string $action 可操作功能：view,create,update,delete(逗號分隔)
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|AgentPermission newModelQuery()
 * @method static Builder<static>|AgentPermission newQuery()
 * @method static Builder<static>|AgentPermission query()
 *
 * @mixin \Eloquent
 */
class AgentPermission extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
