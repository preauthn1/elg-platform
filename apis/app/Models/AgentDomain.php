<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\AgentDomainObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_id 代理系統編號
 * @property string $domain 網址名稱
 * @property int $template_id 模版系統編號,0為公版
 * @property int $mobile_templates 手機模版系統編號
 * @property int $pc_templates 電腦模版系統編號
 * @property string|null $logo_pc 電腦版Logo
 * @property string|null $logo_mobile 手機版Logo
 * @property string|null $icon favorite icon
 * @property string|null $add_home_image 添加主螢幕教學圖片網址
 * @property string|null $title 網站名稱
 * @property int|null $type 類型: 0:正常, 1:推廣用(因為可能會用到短網址, 故其他在回給前端可能不適合), 2:是遊戲商中轉網址跳轉專用
 * @property int $need_login 是否可使用公開頁面
 * @property \Illuminate\Database\Eloquent\Casts\ArrayObject<array-key, mixed>|null $info 其他資訊
 * @property int $status 0未使用,1使用中
 * @property int $sort 排序
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read Agent|null $agent
 * @property-read Template|null $template
 *
 * @method static Builder<static>|AgentDomain newModelQuery()
 * @method static Builder<static>|AgentDomain newQuery()
 * @method static Builder<static>|AgentDomain query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(AgentDomainObserver::class)]
class AgentDomain extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'info' => AsArrayObject::class,
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }
}
