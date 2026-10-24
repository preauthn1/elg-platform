<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $agent_id 代理編號
 * @property int|null $ip 登入ip
 * @property int $ip_1 ip_1
 * @property int $ip_2 ip_2
 * @property int $ip_3 ip_3
 * @property int $ip_4 ip_4
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentLoginIpLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentLoginIpLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentLoginIpLog query()
 *
 * @mixin \Eloquent
 */
class AgentLoginIpLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
