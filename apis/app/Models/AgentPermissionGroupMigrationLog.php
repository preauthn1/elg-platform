<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 預設角色分線遷移的異動備份 target/op_tickets#3168
 *
 * 只由 `patch:split-default-agent-permission-group` 寫入，一列對應一個被改掛的代理。
 * `agents.agent_permission_group_id` 是單一 int 欄位，改掉即無處可查原值——
 * **本表是回滾與稽核的唯一依據，禁止在補丁以外的地方寫入或刪除。**
 *
 * @property int $id 系統編號
 * @property string $batch 執行批次代碼
 * @property int $agent_id 被改掛的代理 agents.id
 * @property null|int $from_group_id 異動前的角色 id，NULL=原本未掛角色
 * @property null|int $to_group_id 異動後的角色 id
 * @property int $top_agent_id 判定出的所屬總代 agents.id
 * @property int $rolled_back 是否已回滾 0:否 1:是
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|AgentPermissionGroupMigrationLog newModelQuery()
 * @method static Builder<static>|AgentPermissionGroupMigrationLog newQuery()
 * @method static Builder<static>|AgentPermissionGroupMigrationLog query()
 *
 * @mixin \Eloquent
 */
class AgentPermissionGroupMigrationLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
