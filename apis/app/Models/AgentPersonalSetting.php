<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\AgentPersonalSettingObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_id 代理id
 * @property int $setting_id 設置鍵值
 * @property string|null $sub_category 子分類(可能是語系)
 * @property string $value 設置value
 * @property Carbon $created_at 建立日期
 * @property int $created_by 建立人
 * @property Carbon $updated_at 更新日期
 * @property int $admin 操作者
 *
 * @method static Builder<static>|AgentPersonalSetting newModelQuery()
 * @method static Builder<static>|AgentPersonalSetting newQuery()
 * @method static Builder<static>|AgentPersonalSetting query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(AgentPersonalSettingObserver::class)]
class AgentPersonalSetting extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
