<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $agent_group_id 設置分組ID (agent_groups type=6)
 * @property int $currency_id 幣別ID
 * @property int $page_type 頁面類型 1:註冊 2:登入
 * @property string $field_key 欄位鍵
 * @property int $field_group 層級/欄位組
 * @property int $sort 顯示順序
 * @property int $is_required 是否必填
 * @property int $status 狀態 0:停用 1:啟用
 * @property array|null $extra_config 欄位子設定
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|AgentMemberFieldSetting newModelQuery()
 * @method static Builder<static>|AgentMemberFieldSetting newQuery()
 * @method static Builder<static>|AgentMemberFieldSetting query()
 *
 * @mixin \Eloquent
 */
class AgentMemberFieldSetting extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'extra_config' => 'array',
    ];
}
