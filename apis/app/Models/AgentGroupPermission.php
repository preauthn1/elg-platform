<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 代理的「組別管理權」：能編輯哪些設定組別（agent_groups：個性化設置、金流、簡訊、客服、遊戲商自訂、
 * 會員欄位、郵件）。跟選單權限（個人 agent_permissions ∪ 角色）是兩套獨立系統，不進生效選單計算。
 *
 * 資料表沿用 API_Agent 既有的 agent_group_permissions（沒有唯一鍵）；新後台改由 apis 讀寫（target/tickets#4422）。
 *
 * @property int $id 系統編號
 * @property int $agent_id 代理 agents.id
 * @property int $agent_group_id 設定組別 agent_groups.id
 * @property int $status 0:無權 1:有權
 * @property int $admin 更新者ID
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|AgentGroupPermission newModelQuery()
 * @method static Builder<static>|AgentGroupPermission newQuery()
 * @method static Builder<static>|AgentGroupPermission query()
 *
 * @mixin \Eloquent
 */
class AgentGroupPermission extends Model
{
    protected $table = 'agent_group_permissions';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
