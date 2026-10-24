<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SEO 元數據 工單4102
 *
 * 前台頁面的 <title>／<meta> 文案，綁在域名身上、每個頁面一列。同一總代線可將一個域名
 * 標為預設，該線其他沒設定的域名回退用它。
 *
 * @property int $id 系統編號
 * @property int $agent_domain_id agent_domains.id
 * @property int $general_agent_id 該域名所屬總代 agents.id (role_id=11)
 * @property string $page 頁面代碼，見 SeoPageEnum
 * @property string $title 頁面標題
 * @property string $description 頁面描述
 * @property string $keywords 關鍵字，逗號分隔
 * @property int $is_default 1=此域名為該總代線的預設回退
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read AgentDomain|null $agentDomain
 *
 * @method static Builder<static>|AgentDomainSeoMeta newModelQuery()
 * @method static Builder<static>|AgentDomainSeoMeta newQuery()
 * @method static Builder<static>|AgentDomainSeoMeta query()
 *
 * @mixin \Eloquent
 */
class AgentDomainSeoMeta extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'integer',
        ];
    }

    /** 所屬域名 */
    public function agentDomain(): BelongsTo
    {
        return $this->belongsTo(AgentDomain::class, 'agent_domain_id');
    }
}
