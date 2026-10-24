<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 電子郵件設置組別的群組設定（形狀比照 SmsGatewayGroupDetail）
 *
 * @property int $id 流水號
 * @property int $agent_group_id agent_groups.id(agent_group_type=7)
 * @property \Illuminate\Database\Eloquent\Casts\ArrayObject<array-key, mixed> $setting 群組設定, json格式; {"token_expired":"token有效時間(分)", "max_send":"有效時間內最大發送次數"}
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID(agents.id)
 *
 * @method static Builder<static>|EmailGatewayGroupDetail newModelQuery()
 * @method static Builder<static>|EmailGatewayGroupDetail newQuery()
 * @method static Builder<static>|EmailGatewayGroupDetail query()
 *
 * @mixin \Eloquent
 */
class EmailGatewayGroupDetail extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'setting' => AsArrayObject::class,
    ];
}
