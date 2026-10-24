<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 代理群組（權限角色）× 選單權限明細 工單4080
 *
 * 結構對齊 AgentPermission，差異僅在主體由 agent_id 換成 agent_permission_group_id。
 * action 與個人權限衝突時取聯集（例：個人 view + 角色 view,update → view,update）。
 *
 * @property int $id 系統編號
 * @property int $agent_permission_group_id 代理群組系統編號(AgentPermissionGroup)
 * @property int $agent_menu_id 代理後台選單系統編號(AgentMenu)
 * @property string $action 可操作功能：view,create,update,delete(逗號分隔)
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|AgentPermissionGroupMenu newModelQuery()
 * @method static Builder<static>|AgentPermissionGroupMenu newQuery()
 * @method static Builder<static>|AgentPermissionGroupMenu query()
 *
 * @mixin \Eloquent
 */
class AgentPermissionGroupMenu extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /** 所屬代理群組 */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AgentPermissionGroup::class, 'agent_permission_group_id');
    }

    /** 對應的後台選單 */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(AgentMenu::class, 'agent_menu_id');
    }
}
