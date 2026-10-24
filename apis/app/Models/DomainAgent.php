<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $domain_id 網域系統編號;
 * @property int $domain_type_info_id 網域資訊系統編號;
 * @property int $agent_id 代理系統編號;
 * @property int $status 0未使用,1使用中
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Agent|null $agent
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DomainAgent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DomainAgent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DomainAgent query()
 *
 * @mixin \Eloquent
 */
class DomainAgent extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    // 代理關聯
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }
}
