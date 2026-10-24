<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $agent_group_id 設置分組ID (agent_groups type=6)
 * @property int $currency_id 幣別ID
 * @property string $field_key 限制欄位鍵
 * @property int $platform_enabled 整個平台上限開關
 * @property int|null $platform_max_limit 整個平台最大限制值
 * @property int $custom_enabled 自訂逐值上限開關
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|AgentRegisterLimit newModelQuery()
 * @method static Builder<static>|AgentRegisterLimit newQuery()
 * @method static Builder<static>|AgentRegisterLimit query()
 *
 * @mixin \Eloquent
 */
class AgentRegisterLimit extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function values(): HasMany
    {
        return $this->hasMany(AgentRegisterLimitValue::class, 'register_limit_id', 'id');
    }
}
