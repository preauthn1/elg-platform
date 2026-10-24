<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id 代理編號
 * @property int|null $ip 登入ip
 * @property int $ip_1 ip_1
 * @property int $ip_2 ip_2
 * @property int $ip_3 ip_3
 * @property int $ip_4 ip_4
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 *
 * @method static Builder<static>|MemberLoginIpLog newModelQuery()
 * @method static Builder<static>|MemberLoginIpLog newQuery()
 * @method static Builder<static>|MemberLoginIpLog query()
 *
 * @mixin \Eloquent
 */
class MemberLoginIpLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
