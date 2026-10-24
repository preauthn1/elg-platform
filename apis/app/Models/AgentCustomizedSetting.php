<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\AgentCustomizedSettingObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id PK流水號
 * @property int $agent_group_id 設置分組ID
 * @property int $setting_key 主要分類鍵值 (請參考 AgentCustomizedSettingKeyEnum)
 * @property string $sub_category 子分類 (同一組key、可能有多種設置 e.g. 語系)
 * @property string $value 設值數值
 * @property string $memo 備註
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|AgentCustomizedSetting newModelQuery()
 * @method static Builder<static>|AgentCustomizedSetting newQuery()
 * @method static Builder<static>|AgentCustomizedSetting query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(AgentCustomizedSettingObserver::class)]
class AgentCustomizedSetting extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
